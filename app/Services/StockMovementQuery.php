<?php

namespace App\Services;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Query riwayat pergerakan stok (semua barang) dengan filter barang, jenis, dan rentang waktu, plus
 * ringkasan unit masuk/keluar. Dipakai halaman Barang → Riwayat stok dan GET /barang/riwayat-stok/data.
 */
class StockMovementQuery
{
    /** @param  array{product_id?: ?int, type?: ?string, from?: ?Carbon, to?: ?Carbon}  $f */
    public function query(array $f = []): Builder
    {
        return StockMovement::query()
            ->when($f['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when(array_key_exists($f['type'] ?? '', StockMovement::TYPES), fn ($q) => $q->where('type', $f['type']))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d));
    }

    /** @return array{count: int, in: int, out: int} unit masuk & keluar (penyesuaian menurut arah selisih) */
    public function summary(array $f = []): array
    {
        $in = $out = 0;
        $rows = $this->query($f)->get(['type', 'quantity', 'stock_before', 'stock_after']);
        foreach ($rows as $m) {
            $d = $m->signed_quantity;
            $d >= 0 ? $in += $d : $out += -$d;
        }

        return ['count' => $rows->count(), 'in' => $in, 'out' => $out];
    }

    public static function toArray(StockMovement $m): array
    {
        return ['id' => $m->id, 'product_id' => $m->product_id, 'product_code' => $m->product?->code, 'product_name' => $m->product?->name,
            'type' => $m->type, 'type_label' => $m->type_label, 'change' => $m->signed_quantity, 'stock_before' => $m->stock_before,
            'stock_after' => $m->stock_after, 'note' => $m->note, 'user' => $m->user?->name, 'created_at' => $m->created_at->toDateTimeString()];
    }
}
