<?php

namespace App\Services;

use App\Livewire\Promo\Index;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Promo;
use App\Models\TransactionItem;
use Illuminate\Support\Carbon;

/**
 * Data halaman & API Diskon & Promo dari database:
 * - all() / coupons() / stats(): daftar promo (status, aturan, jadwal, pemakaian & potongan dari
 *   transaction_discounts), kupon (status, sisa kuota), dan angka ringkasan;
 * - timeline(): batang linimasa promo & kupon dalam satu jendela tanggal, dengan tanda bentrok
 *   (promo aktif bersamaan pada cakupan yang beririsan);
 * - impact() / impacts(): dampak promo — rata-rata penjualan harian barang yang dicakup 7 hari
 *   sebelum promo vs selama promo, potongan yang diberikan, dan efek bersih.
 */
class PromoService
{
    public const BEFORE_DAYS = 7;

    public function __construct(private CouponService $coupons, private ManualDiscountService $manual) {}

    /** Semua promo (terbaru mulai dulu) dengan pemakaian & potongan. */
    public function all(): array
    {
        return Promo::with('targets.category:id,name', 'targets.product:id,name', 'creator:id,name')
            ->withCount('discounts')->withSum('discounts', 'amount')
            ->orderByDesc('starts_on')->orderBy('id')->get()
            ->map(fn (Promo $p) => [
                ...PromoEngine::toArray($p),
                'usage_count' => (int) $p->discounts_count,
                'discount_given' => (int) round((float) $p->discounts_sum_amount),
            ])->all();
    }

    /** Semua kupon: aktif dulu, lalu terjadwal, habis, berakhir, nonaktif. */
    public function coupons(): array
    {
        $order = ['aktif' => 0, 'terjadwal' => 1, 'habis' => 2, 'berakhir' => 3, 'nonaktif' => 4];

        return Coupon::orderBy('code')->get()->map(fn (Coupon $c) => CouponService::toArray($c))
            ->sortBy(fn ($c) => $order[$c['status']] ?? 9)->values()->all();
    }

    /** Aturan diskon manual + pemakaian 7 hari terakhir. */
    public function manualRules(): array
    {
        $usage = $this->manual->usage(7);

        return [...$this->manual->rules(), 'used_7_days' => $usage['count'], 'given_7_days' => $usage['amount']];
    }

    public function stats(?array $promos = null, ?array $coupons = null): array
    {
        $promos = collect($promos ?? $this->all());
        $coupons = collect($coupons ?? $this->coupons());
        $manual = $this->manual->usage(7);

        return [
            'active_promos' => $promos->where('status', 'aktif')->count(),
            'scheduled_promos' => $promos->where('status', 'terjadwal')->count(),
            'active_coupons' => $coupons->where('status', 'aktif')->count(),
            'coupon_uses' => $coupons->sum('used'),
            'promo_uses' => $promos->sum('usage_count'),
            'discount_given' => $promos->sum('discount_given') + $manual['amount'],
        ];
    }

    /**
     * Baris linimasa promo & kupon yang beririsan dengan [$from, $to]: posisi batang (%) dan
     * bentrok antarpromo. Promo dulu, lalu kupon; masing-masing urut tanggal mulai.
     */
    public function timeline(Carbon $from, Carbon $to): array
    {
        $span = (int) $from->diffInDays($to) + 1;
        $promos = collect($this->all());
        $bar = function (string $start, string $end) use ($from, $to, $span) {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
            if ($e->lt($from) || $s->gt($to)) {
                return null;
            }
            $left = max(0, (int) $from->diffInDays($s, false));
            $right = min($span - 1, (int) $from->diffInDays($e, false));

            return ['left' => round($left / $span * 100, 2), 'width' => round(($right - $left + 1) / $span * 100, 2),
                'clip_start' => $s->lt($from), 'clip_end' => $e->gt($to)];
        };

        $rows = [];
        foreach ($promos as $p) {
            if (! ($b = $bar($p['starts_at'], $p['ends_at']))) {
                continue;
            }
            $clashes = $promos->filter(fn ($o) => $o['id'] !== $p['id'] && $o['is_active'] && $p['is_active']
                && $o['starts_at'] <= $p['ends_at'] && $o['ends_at'] >= $p['starts_at'] && self::overlaps($p, $o))->pluck('name')->values()->all();
            $rows[] = ['kind' => 'promo', 'id' => $p['id'], 'name' => $p['name'], 'status' => $p['status'], 'starts_at' => $p['starts_at'], 'ends_at' => $p['ends_at'],
                'detail' => Index::describe($p), 'clashes' => $clashes, ...$b];
        }
        foreach ($this->coupons() as $c) {
            if (! ($b = $bar($c['starts_at'], $c['ends_at']))) {
                continue;
            }
            $rows[] = ['kind' => 'kupon', 'id' => $c['id'], 'name' => $c['code'], 'status' => $c['status'], 'starts_at' => $c['starts_at'], 'ends_at' => $c['ends_at'],
                'detail' => $c['name'], 'clashes' => [], ...$b];
        }

        usort($rows, fn ($a, $b) => [$a['kind'] === 'kupon', $a['starts_at']] <=> [$b['kind'] === 'kupon', $b['starts_at']]);

        return $rows;
    }

    /** Dampak semua promo yang sudah mulai, efek bersih terbaik dulu. */
    public function impacts(): array
    {
        return collect($this->all())
            ->filter(fn ($p) => Carbon::parse($p['starts_at'])->lte(today()))
            ->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'status' => $p['status'], 'usage' => $p['usage_count'], ...$this->impact($p)])
            ->sortByDesc('net_effect')->values()->all();
    }

    /**
     * Dampak satu promo (array dari all()): penjualan harian barang yang dicakup sebelum vs
     * selama promo, potongan, dan efek bersih = tambahan penjualan − potongan.
     *
     * @return array{days_running: int, before_daily: int, during_daily: int, uplift: ?int, units: int, revenue: int, discount: int, net_effect: int}
     */
    public function impact(array $promo): array
    {
        $start = Carbon::parse($promo['starts_at'])->startOfDay();
        $end = min(Carbon::parse($promo['ends_at'])->startOfDay(), today());
        $days = max(1, (int) $start->diffInDays($end) + 1);
        $productIds = $this->coveredProductIds($promo);

        $sales = function (Carbon $from, Carbon $to) use ($productIds) {
            return TransactionItem::query()
                ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
                ->whereBetween('transactions.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->whereNull('transactions.voided_at')
                ->when($productIds !== null, fn ($q) => $q->whereIn('transaction_items.product_id', $productIds))
                ->selectRaw('COALESCE(SUM(transaction_items.subtotal), 0) AS revenue, COALESCE(SUM(transaction_items.quantity), 0) AS units')
                ->toBase()->first();
        };
        $before = $sales($start->copy()->subDays(self::BEFORE_DAYS), $start->copy()->subDay());
        $during = $sales($start, $end);

        $beforeDaily = (int) round((float) $before->revenue / self::BEFORE_DAYS);
        $duringDaily = (int) round((float) $during->revenue / $days);
        $discount = (int) ($promo['discount_given'] ?? 0);

        return [
            'days_running' => $days,
            'before_daily' => $beforeDaily,
            'during_daily' => $duringDaily,
            'uplift' => $beforeDaily > 0 ? (int) round(($duringDaily - $beforeDaily) / $beforeDaily * 100) : null,
            'units' => (int) $during->units,
            'revenue' => (int) round((float) $during->revenue),
            'discount' => $discount,
            'net_effect' => (int) round((float) $during->revenue) - $beforeDaily * $days - $discount,
        ];
    }

    /** Id barang yang dicakup promo; null = semua barang. */
    private function coveredProductIds(array $promo): ?array
    {
        return match ($promo['scope']) {
            'barang' => $promo['product_ids'],
            'kategori' => Product::whereHas('category', fn ($q) => $q->whereIn('name', $promo['targets']))->pluck('id')->all(),
            default => null,
        };
    }

    /** Dua promo bentrok bila cakupannya beririsan (semua barang selalu beririsan). */
    private static function overlaps(array $a, array $b): bool
    {
        if ($a['scope'] === 'semua' || $b['scope'] === 'semua') {
            return true;
        }

        return $a['scope'] === $b['scope'] && array_intersect($a['targets'], $b['targets']) !== [];
    }
}
