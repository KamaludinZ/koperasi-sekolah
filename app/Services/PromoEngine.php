<?php

namespace App\Services;

use App\Models\Promo;
use App\Support\PromoMatcher;
use Illuminate\Support\Carbon;

/**
 * Mesin promo otomatis: mengambil promo yang berjalan hari ini dari database (aktif & dalam
 * periode), lalu mencocokkannya dengan baris belanja lewat PromoMatcher (hari & jam berlaku,
 * cakupan semua/kategori/barang, minimal belanja & jumlah; satu promo terbaik per barang,
 * tidak bertumpuk). Dipakai keranjang kasir (pratinjau hemat) dan CheckoutService (potongan nyata).
 */
class PromoEngine
{
    /** @var array<string, list<array>> promo berjalan per tanggal (cache per permintaan) */
    private array $cache = [];

    /**
     * @param  list<array{id: int, name: string, category: ?string, price: int, quantity: int, subtotal: int}>  $lines
     * @return array{applied: list<array{id: int, name: string, rule: string, items: list<string>, saving: int}>, hints: list<array>, total: int}
     */
    public function evaluate(array $lines, ?Carbon $now = null): array
    {
        $now ??= now();

        return PromoMatcher::match($lines, $this->livePromos($now), $now);
    }

    /** Promo yang berjalan pada tanggal $now, dalam bentuk array PromoMatcher. */
    public function livePromos(?Carbon $now = null): array
    {
        $day = ($now ?? now())->toDateString();

        return $this->cache[$day] ??= Promo::runningOn($day)
            ->with('targets.category:id,name', 'targets.product:id,name', 'creator:id,name')
            ->orderBy('id')->get()
            ->map(fn (Promo $p) => self::toArray($p))->all();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /** Bentuk array promo (sama dengan data tahap frontend) + id barang sasaran. */
    public static function toArray(Promo $p): array
    {
        $p->loadMissing('targets.category:id,name', 'targets.product:id,name');

        return [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'value' => (int) round((float) $p->value),
            'buy_qty' => $p->buy_qty,
            'free_qty' => $p->free_qty,
            'min_qty' => $p->min_qty,
            'min_purchase' => (int) round((float) $p->min_purchase),
            'scope' => $p->scope,
            'targets' => $p->targets->map(fn ($t) => $t->category?->name ?? $t->product?->name)->filter()->values()->all(),
            'product_ids' => $p->targets->pluck('product_id')->filter()->values()->all(),
            'starts_at' => $p->starts_on->toDateString(),
            'ends_at' => $p->ends_on->toDateString(),
            'days' => array_map('intval', $p->days ?? []),
            'time_from' => $p->time_from ? substr($p->time_from, 0, 5) : null,
            'time_to' => $p->time_to ? substr($p->time_to, 0, 5) : null,
            'is_active' => $p->is_active,
            'status' => $p->status,
            'created_by' => $p->creator?->name,
        ];
    }
}
