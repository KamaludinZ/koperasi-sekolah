<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Peringkat barang paling laku: jumlah terjual per barang dalam N hari terakhir dari transaksi yang tidak dibatalkan,
 * dikurangi jumlah retur yang disetujui. Disimpan sementara 10 menit agar layar kasir tetap cepat — peringkat tidak
 * perlu seketika berubah setiap ada penjualan.
 */
class BestSellers
{
    public const CACHE_SECONDS = 600;

    /** @return array<int, int> product_id → jumlah terjual bersih (> 0), urut terbanyak */
    public function ranking(int $days): array
    {
        $days = max(1, min(366, $days));

        return Cache::remember("kasir:terlaris:{$days}", self::CACHE_SECONDS, function () use ($days) {
            $since = now()->subDays($days - 1)->startOfDay();
            $sold = DB::table('transaction_items as ti')->join('transactions as t', 't.id', '=', 'ti.transaction_id')
                ->where('t.created_at', '>=', $since)->whereNull('t.voided_at')->whereNotNull('ti.product_id')
                ->groupBy('ti.product_id')->selectRaw('ti.product_id as id, SUM(ti.quantity) as qty')->pluck('qty', 'id');
            $returned = DB::table('transaction_return_items as ri')
                ->join('transaction_returns as r', 'r.id', '=', 'ri.transaction_return_id')
                ->join('transaction_items as ti', 'ti.id', '=', 'ri.transaction_item_id')
                ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
                ->where('r.status', 'disetujui')->where('t.created_at', '>=', $since)->whereNull('t.voided_at')->whereNotNull('ri.product_id')
                ->groupBy('ri.product_id')->selectRaw('ri.product_id as id, SUM(ri.quantity) as qty')->pluck('qty', 'id');
            $net = [];
            foreach ($sold as $id => $qty) {
                if (($n = (int) $qty - (int) ($returned[$id] ?? 0)) > 0) {
                    $net[(int) $id] = $n;
                }
            }
            arsort($net);

            return $net;
        });
    }

    /** ID barang terlaris teratas. @return list<int> */
    public function top(int $days, int $count): array
    {
        return $count > 0 ? array_slice(array_keys($this->ranking($days)), 0, $count) : [];
    }
}
