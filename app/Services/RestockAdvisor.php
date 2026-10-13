<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Saran restok berbasis kecepatan penjualan: rata-rata terjual per hari dalam WINDOW_DAYS hari terakhir
 * (transaksi yang tidak dibatalkan, dikurangi retur disetujui), perkiraan stok habis dalam berapa hari,
 * dan jumlah yang disarankan dipesan agar stok cukup untuk `horizon` hari ke depan ditambah batas
 * minimum. Barang tanpa penjualan memakai aturan lama: kembalikan ke 2× batas minimum.
 */
class RestockAdvisor
{
    public const WINDOW_DAYS = 30;

    public const HORIZONS = [7 => '1 minggu', 14 => '2 minggu', 30 => '1 bulan'];

    /** Barang dianggap perlu restok bila stok ≤ batas minimum atau habis dalam kurang dari sekian hari. */
    public const URGENT_DAYS = 7;

    /**
     * @return list<array{id: int, code: string, name: string, category: ?string, unit: string, stock: int, min_stock: int,
     *     sold: int, per_day: float, days_left: ?int, suggested: int, cost: int, reason: string, level: string}>
     */
    public function suggestions(int $horizon = 14, bool $all = false): array
    {
        $since = now()->subDays(self::WINDOW_DAYS)->startOfDay();
        $sold = DB::table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->whereNull('t.voided_at')->where('t.created_at', '>=', $since)
            ->groupBy('ti.product_id')->selectRaw('ti.product_id, SUM(ti.quantity) AS qty')
            ->pluck('qty', 'product_id');
        $returned = DB::table('transaction_return_items as ri')
            ->join('transaction_returns as r', 'r.id', '=', 'ri.transaction_return_id')
            ->join('transactions as t', 't.id', '=', 'r.transaction_id')
            ->where('r.status', 'disetujui')->where('r.kind', 'retur')->whereNull('t.voided_at')->where('t.created_at', '>=', $since)
            ->groupBy('ri.product_id')->selectRaw('ri.product_id, SUM(ri.quantity) AS qty')
            ->pluck('qty', 'product_id');

        $rows = Product::active()->with('category:id,name')->orderBy('name')->get()->map(function (Product $p) use ($sold, $returned, $horizon) {
            $qty = max(0, (int) ($sold[$p->id] ?? 0) - (int) ($returned[$p->id] ?? 0));
            $perDay = $qty / self::WINDOW_DAYS;
            $daysLeft = $perDay > 0 ? (int) floor($p->stock / $perDay) : null;
            $suggested = $perDay > 0
                ? max(0, (int) ceil($perDay * $horizon) + $p->min_stock - $p->stock)
                : max(0, $p->min_stock * 2 - $p->stock);
            $level = match (true) {
                $p->stock === 0 => 'habis',
                $p->stock <= $p->min_stock || ($daysLeft !== null && $daysLeft < self::URGENT_DAYS) => 'segera',
                $suggested > 0 => 'rencana',
                default => 'aman',
            };

            return [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'category' => $p->category?->name, 'unit' => $p->unit,
                'stock' => $p->stock, 'min_stock' => $p->min_stock, 'sold' => $qty, 'per_day' => round($perDay, 2),
                'days_left' => $daysLeft, 'suggested' => $suggested, 'cost' => (int) round((float) $p->cost_price),
                'reason' => $perDay > 0 ? 'Cukup untuk '.$horizon.' hari + batas minimum' : 'Belum ada penjualan — kembali ke 2× batas minimum',
                'level' => $level,
            ];
        });

        $rank = ['habis' => 0, 'segera' => 1, 'rencana' => 2, 'aman' => 3];

        return $rows->filter(fn ($r) => $all || $r['level'] !== 'aman')
            ->sortBy(fn ($r) => [$rank[$r['level']], $r['days_left'] ?? PHP_INT_MAX, $r['name']])
            ->values()->all();
    }
}
