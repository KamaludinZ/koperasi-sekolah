<?php

namespace App\Livewire\Promo;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Jadwal & dampak promo: linimasa periode promo & kupon (4 minggu lalu s.d. 8 minggu ke depan,
 * bisa digeser), tanda hari ini, bentrok promo pada cakupan yang sama, serta dampak tiap promo
 * terhadap penjualan (rata-rata harian sebelum vs selama promo, potongan, efek bersih).
 * Tahap frontend: data tiruan MockData::promos/coupons/promoImpact.
 */
#[Layout('components.layouts.app')]
#[Title('Jadwal & Dampak Promo')]
class Schedule extends Component
{
    /** Panjang jendela linimasa (hari) dan pergeseran per klik. */
    public const WINDOW_DAYS = 84;

    public const STEP_DAYS = 28;

    /** Pergeseran jendela dalam minggu dari posisi bawaan (0 = 4 minggu lalu s.d. 8 minggu ke depan). */
    #[Url(as: 'geser', except: 0)]
    public int $offset = 0;

    public function move(int $direction): void
    {
        $this->offset = max(-12, min(12, $this->offset + ($direction <=> 0) * (self::STEP_DAYS / 7)));
    }

    public function resetWindow(): void
    {
        $this->offset = 0;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function window(): array
    {
        $start = today()->subDays(28)->addWeeks($this->offset)->startOfWeek();

        return [$start, $start->copy()->addDays(self::WINDOW_DAYS - 1)];
    }

    /**
     * Baris linimasa: promo & kupon yang beririsan dengan jendela, dengan posisi batang (%) dan
     * tanda bentrok (promo lain berjalan bersamaan pada cakupan yang beririsan).
     */
    #[Computed]
    public function rows(): array
    {
        [$from, $to] = $this->window;
        $span = self::WINDOW_DAYS;
        $promos = collect(MockData::promos());

        $bar = function (string $start, string $end) use ($from, $to, $span) {
            $s = Carbon::parse($start);
            $e = Carbon::parse($end);
            if ($e->lt($from) || $s->gt($to)) {
                return null;
            }
            $left = max(0, $from->diffInDays($s, false));
            $right = min($span - 1, $from->diffInDays($e, false));

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
        foreach (MockData::coupons() as $c) {
            if (! ($b = $bar($c['starts_at'], $c['ends_at']))) {
                continue;
            }
            $rows[] = ['kind' => 'kupon', 'id' => $c['id'], 'name' => $c['code'], 'status' => $c['status'], 'starts_at' => $c['starts_at'], 'ends_at' => $c['ends_at'],
                'detail' => $c['name'], 'clashes' => [], ...$b];
        }

        usort($rows, fn ($a, $b) => [$a['kind'] === 'kupon', $a['starts_at']] <=> [$b['kind'] === 'kupon', $b['starts_at']]);

        return $rows;
    }

    /** Label minggu untuk sumbu linimasa: posisi (%) dan tanggal awal minggu. */
    #[Computed]
    public function weeks(): array
    {
        [$from] = $this->window;

        return collect(range(0, self::WINDOW_DAYS / 7 - 1))->map(fn ($w) => [
            'left' => round($w * 7 / self::WINDOW_DAYS * 100, 2),
            'label' => $from->copy()->addWeeks($w)->translatedFormat('d M'),
        ])->all();
    }

    /** Posisi garis hari ini (%), null bila di luar jendela. */
    #[Computed]
    public function todayAt(): ?float
    {
        [$from, $to] = $this->window;

        return today()->between($from, $to) ? round(($from->diffInDays(today()) + 0.5) / self::WINDOW_DAYS * 100, 2) : null;
    }

    /** Dampak promo yang sudah berjalan, efek bersih terbaik dulu. */
    #[Computed]
    public function impacts(): array
    {
        return collect(MockData::promos())
            ->map(fn ($p) => ($i = MockData::promoImpact($p)) ? ['id' => $p['id'], 'name' => $p['name'], 'status' => $p['status'], 'usage' => $p['usage_count'], ...$i] : null)
            ->filter()->sortByDesc('net_effect')->values()->all();
    }

    /** Dua promo bentrok bila cakupannya beririsan (semua barang selalu beririsan). */
    private static function overlaps(array $a, array $b): bool
    {
        if ($a['scope'] === 'semua' || $b['scope'] === 'semua') {
            return true;
        }

        return $a['scope'] === $b['scope'] && array_intersect($a['targets'], $b['targets']) !== [];
    }

    public function render()
    {
        return view('livewire.promo.schedule');
    }
}
