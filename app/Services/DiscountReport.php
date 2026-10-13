<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionDiscount;
use Illuminate\Support\Carbon;

/**
 * Laporan dampak diskon & promo terhadap penjualan dalam satu periode, dari transaction_discounts:
 * total potongan per sumber (promo otomatis, kupon, diskon manual), penjualan kotor vs bersih,
 * porsi transaksi berdiskon, rata-rata belanja transaksi berdiskon vs tanpa diskon, rincian per
 * promo/kupon, dan potongan per hari. Dipakai halaman Laporan, API /laporan/diskon, dan unduhan.
 */
class DiscountReport
{
    public function report(Carbon $start, Carbon $end): array
    {
        // Hanya membaca snapshot transaksi & potongannya, jadi cukup diinvalidasi oleh versi ReportCache.
        return \App\Support\ReportCache::remember('discounts', [$start->copy()->startOfDay(), $end->copy()->endOfDay()],
            fn () => $this->computeReport($start, $end));
    }

    private function computeReport(Carbon $start, Carbon $end): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $tx = Transaction::whereBetween('created_at', [$start, $end])->whereNull('voided_at')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(subtotal), 0) AS gross, COALESCE(SUM(total), 0) AS net,
                SUM(CASE WHEN discount > 0 THEN 1 ELSE 0 END) AS discounted,
                COALESCE(SUM(CASE WHEN discount > 0 THEN total ELSE 0 END), 0) AS discounted_total')
            ->toBase()->first();
        $count = (int) $tx->n;
        $discounted = (int) $tx->discounted;
        $discountedTotal = (int) round((float) $tx->discounted_total);
        $plainTotal = (int) round((float) $tx->net) - $discountedTotal;

        $rows = TransactionDiscount::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_discounts.transaction_id')
            ->whereBetween('transactions.created_at', [$start, $end])
            ->whereNull('transactions.voided_at');

        $bySource = (clone $rows)->groupBy('transaction_discounts.source')
            ->selectRaw('transaction_discounts.source AS source, COUNT(*) AS n, COALESCE(SUM(transaction_discounts.amount), 0) AS amount')
            ->toBase()->get()->keyBy('source');
        $items = (clone $rows)->whereIn('transaction_discounts.source', ['promo', 'kupon'])
            ->groupBy('transaction_discounts.source', 'transaction_discounts.promo_id', 'transaction_discounts.coupon_id', 'transaction_discounts.label')
            ->selectRaw('transaction_discounts.source AS source, transaction_discounts.promo_id AS promo_id, transaction_discounts.coupon_id AS coupon_id,
                transaction_discounts.label AS label, COUNT(*) AS n, COALESCE(SUM(transaction_discounts.amount), 0) AS amount')
            ->toBase()->get();
        $daily = (clone $rows)->get(['transactions.created_at', 'transaction_discounts.amount'])
            ->groupBy(fn ($r) => Carbon::parse($r->created_at)->toDateString())
            ->map(fn ($g) => (int) round((float) $g->sum('amount')));

        $sources = collect(TransactionDiscount::SOURCES)->map(fn ($label, $key) => [
            'label' => $label,
            'count' => (int) ($bySource[$key]->n ?? 0),
            'amount' => (int) round((float) ($bySource[$key]->amount ?? 0)),
        ])->all();
        $total = array_sum(array_column($sources, 'amount'));
        $row = fn ($r) => ['id' => $r->promo_id ?? $r->coupon_id, 'label' => $r->label, 'uses' => (int) $r->n, 'amount' => (int) round((float) $r->amount)];

        $days = [];
        for ($d = $start->copy(); $d->lte($end) && $d->lte(today()); $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'amount' => $daily[$d->toDateString()] ?? 0];
        }

        return [
            'summary' => [
                'total_discount' => $total,
                'gross_sales' => (int) round((float) $tx->gross),
                'net_sales' => (int) round((float) $tx->net),
                'discount_rate' => (float) $tx->gross > 0 ? round($total / (float) $tx->gross * 100, 1) : 0.0,
                'transactions' => $count,
                'discounted_transactions' => $discounted,
                'discounted_share' => $count ? (int) round($discounted / $count * 100) : 0,
                'avg_discounted' => $discounted ? (int) round($discountedTotal / $discounted) : 0,
                'avg_plain' => ($count - $discounted) ? (int) round($plainTotal / ($count - $discounted)) : 0,
            ],
            'by_source' => $sources,
            'promos' => $items->where('source', 'promo')->map($row)->sortByDesc('amount')->values()->all(),
            'coupons' => $items->where('source', 'kupon')->map($row)->sortByDesc('amount')->values()->all(),
            'daily' => $days,
        ];
    }
}
