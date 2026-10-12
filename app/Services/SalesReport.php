<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Agregasi laporan penjualan untuk pengurus: ringkasan periode (omzet, laba kotor,
 * margin, transaksi, barang, rata-rata) + pembanding periode setara, ringkasan hari ini,
 * barang terlaris, rekap per hari, dan titik grafik per hari/minggu.
 * Laba kotor memakai snapshot harga modal item (transaction_items.cost_price).
 */
class SalesReport
{
    public const PERIODS = [
        'hari-ini' => 'Hari ini',
        '7-hari' => '7 hari',
        '30-hari' => '30 hari',
        'bulan-ini' => 'Bulan ini',
    ];

    public const CHART_PERIODS = [
        '7-hari' => '7 hari',
        '30-hari' => '30 hari',
        '12-minggu' => '12 minggu',
    ];

    /** Jam operasional untuk grafik per jam hari ini. */
    public const OPEN_HOURS = [7, 15];

    /** @return array{0: Carbon, 1: Carbon} rentang periode s.d. saat ini */
    public static function range(string $period): array
    {
        $now = now();

        return match ($period) {
            '7-hari' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()],
            '30-hari' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()],
            'bulan-ini' => [$now->copy()->startOfMonth(), $now->copy()],
            default => [$now->copy()->startOfDay(), $now->copy()],
        };
    }

    /**
     * Rentang pembanding yang setara: hari ini ↔ kemarin s.d. jam yang sama,
     * N hari ↔ N hari sebelumnya, bulan ini ↔ tanggal yang sama di bulan lalu.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousRange(string $period): array
    {
        [$start, $end] = self::range($period);

        return match ($period) {
            '7-hari' => [$start->copy()->subDays(7), $end->copy()->subDays(7)],
            '30-hari' => [$start->copy()->subDays(30), $end->copy()->subDays(30)],
            'bulan-ini' => [$start->copy()->subMonthNoOverflow(), $end->copy()->subMonthNoOverflow()],
            default => [$start->copy()->subDay(), $end->copy()->subDay()],
        };
    }

    /** Ringkasan penjualan dalam rentang (agregat SQL). */
    public function summary(Carbon $start, Carbon $end): array
    {
        $tx = Transaction::query()->betweenDates($start, $end)->toBase()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS revenue, COALESCE(SUM(item_count), 0) AS items')
            ->first();
        $cost = (float) $this->items($start, $end)->toBase()
            ->selectRaw('COALESCE(SUM(transaction_items.cost_price * transaction_items.quantity), 0) AS cost')
            ->value('cost');

        $count = (int) $tx->n;
        $revenue = (int) round((float) $tx->revenue);
        $profit = $revenue - (int) round($cost);

        return [
            'revenue' => $revenue,
            'count' => $count,
            'items' => (int) $tx->items,
            'gross_profit' => $profit,
            'margin' => $revenue > 0 ? (int) round($profit / $revenue * 100) : 0,
            'average' => $count ? (int) round($revenue / $count) : 0,
        ];
    }

    /** Ringkasan periode + periode pembanding dan persentase perubahan tiap angka. */
    public function periodReport(string $period): array
    {
        [$start, $end] = self::range($period);
        [$prevStart, $prevEnd] = self::previousRange($period);
        $current = $this->summary($start, $end);
        $previous = $this->summary($prevStart, $prevEnd);

        $changes = [];
        foreach (['revenue', 'gross_profit', 'count', 'items', 'average'] as $key) {
            $changes[$key] = $previous[$key] > 0
                ? (int) round(($current[$key] - $previous[$key]) / $previous[$key] * 100)
                : null;
        }

        return [
            'summary' => $current,
            'comparison' => [
                'previous' => $previous,
                'changes' => $changes,
                'label' => match ($period) {
                    '7-hari' => '7 hari sebelumnya',
                    '30-hari' => '30 hari sebelumnya',
                    'bulan-ini' => 'periode sama bulan lalu',
                    default => 'kemarin jam segini',
                },
                'range_label' => $prevStart->isSameDay($prevEnd)
                    ? $prevStart->translatedFormat('d M Y').' s.d. '.$prevEnd->format('H:i')
                    : $prevStart->translatedFormat('d M').' – '.$prevEnd->translatedFormat('d M Y'),
            ],
        ];
    }

    /**
     * Ringkasan hari ini dibanding kemarin sampai jam yang sama, barang terlaris hari ini,
     * dan penjualan per jam (jam operasional) untuk grafik mini.
     */
    public function today(): array
    {
        $now = now();
        $start = $now->copy()->startOfDay();
        $today = $this->summary($start, $now);
        $before = $this->summary($start->copy()->subDay(), $now->copy()->subDay())['revenue'];
        $best = $this->topProducts($start, $now, 1)[0] ?? null;

        [$open, $close] = self::OPEN_HOURS;
        $hourly = collect(range($open, $close))->mapWithKeys(fn ($h) => [$h => 0])->all();
        $rows = Transaction::query()->betweenDates($start, $now)->get(['created_at', 'total']);
        foreach ($rows as $t) {
            $h = (int) $t->created_at->format('G');
            if (array_key_exists($h, $hourly)) {
                $hourly[$h] += (int) round((float) $t->total);
            }
        }

        return [
            'revenue' => $today['revenue'],
            'count' => $today['count'],
            'items' => $today['items'],
            'compare' => $before,
            'change' => $before > 0 ? (int) round(($today['revenue'] - $before) / $before * 100) : null,
            'best' => $best ? ['name' => $best['name'], 'quantity' => $best['quantity']] : null,
            'last_at' => $rows->max('created_at')?->toDateTimeString(),
            'hourly' => $hourly,
            'current_hour' => (int) $now->format('G'),
        ];
    }

    /**
     * Barang terlaris (jumlah terjual) dalam rentang, dikelompokkan per nama di struk.
     *
     * @return list<array{name: string, quantity: int, revenue: int, profit: int}>
     */
    public function topProducts(Carbon $start, Carbon $end, ?int $limit = 5): array
    {
        return $this->items($start, $end)->toBase()
            ->groupBy('transaction_items.product_name')
            ->selectRaw('transaction_items.product_name AS name, SUM(transaction_items.quantity) AS quantity,
                SUM(transaction_items.subtotal) AS revenue,
                SUM(transaction_items.subtotal - transaction_items.cost_price * transaction_items.quantity) AS profit')
            ->orderByDesc('quantity')
            ->orderBy('name')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'quantity' => (int) $r->quantity,
                'revenue' => (int) round((float) $r->revenue),
                'profit' => (int) round((float) $r->profit),
            ])
            ->all();
    }

    /**
     * Rekap per tanggal (lama → baru), hanya tanggal yang ada penjualan.
     *
     * @return list<array{date: string, count: int, items: int, revenue: int}>
     */
    public function daily(Carbon $start, Carbon $end): array
    {
        return Transaction::query()->betweenDates($start, $end)
            ->get(['created_at', 'total', 'item_count'])
            ->groupBy(fn ($t) => $t->created_at->toDateString())
            ->sortKeys()
            ->map(fn ($day, $date) => [
                'date' => $date,
                'count' => $day->count(),
                'items' => (int) $day->sum('item_count'),
                'revenue' => (int) round((float) $day->sum('total')),
            ])
            ->values()
            ->all();
    }

    public const CHART_UNITS = ['hari', 'minggu', 'bulan'];

    /**
     * Titik grafik untuk pilihan cepat: 7/30 hari (per hari) atau 12 minggu (per minggu).
     *
     * @return list<array{date: string, end: string, label: string, tooltip: string, value: int, count: int}>
     */
    public function chartPoints(string $chartPeriod): array
    {
        $now = now();

        return match ($chartPeriod) {
            '30-hari' => $this->chart($now->copy()->subDays(29)->startOfDay(), $now, 'hari', 'd/m'),
            '12-minggu' => $this->chart($now->copy()->startOfWeek()->subWeeks(11), $now, 'minggu'),
            default => $this->chart($now->copy()->subDays(6)->startOfDay(), $now, 'hari', 'D d'),
        };
    }

    /** Satuan grafik yang wajar untuk panjang rentang: ≤ 62 hari per hari, ≤ 1 tahun per minggu, selebihnya per bulan. */
    public static function unitFor(Carbon $start, Carbon $end): string
    {
        $days = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;

        return $days <= 62 ? 'hari' : ($days <= 366 ? 'minggu' : 'bulan');
    }

    /**
     * Titik grafik penjualan per hari/minggu/bulan dalam rentang, termasuk periode
     * tanpa penjualan (nilai 0) agar sumbu waktu tidak bolong. Minggu dimulai Senin;
     * titik pertama/terakhir bisa berupa minggu/bulan parsial.
     *
     * @return list<array{date: string, end: string, label: string, tooltip: string, value: int, count: int}>
     */
    public function chart(Carbon $start, Carbon $end, string $unit = 'hari', ?string $dayLabel = null): array
    {
        $bucketStart = fn (Carbon $d) => match ($unit) {
            'minggu' => $d->copy()->startOfWeek(),
            'bulan' => $d->copy()->startOfMonth(),
            default => $d->copy()->startOfDay(),
        };
        $dayLabel ??= $start->diffInDays($end) <= 7 ? 'D d' : 'd/m';

        $buckets = [];
        for ($d = $bucketStart($start); $d->lte($end); $unit === 'minggu' ? $d->addWeek() : ($unit === 'bulan' ? $d->addMonthNoOverflow() : $d->addDay())) {
            $last = match ($unit) {
                'minggu' => $d->copy()->endOfWeek(),
                'bulan' => $d->copy()->endOfMonth(),
                default => $d->copy()->endOfDay(),
            };
            $buckets[$d->toDateString()] = [
                'date' => $d->toDateString(),
                'end' => $last->toDateString(),
                'label' => match ($unit) {
                    'minggu' => $d->translatedFormat('d M'),
                    'bulan' => $d->translatedFormat('M Y'),
                    default => $d->translatedFormat($dayLabel),
                },
                'tooltip' => match ($unit) {
                    'minggu' => 'Minggu '.$d->translatedFormat('d M').' – '.$last->translatedFormat('d M Y'),
                    'bulan' => $d->translatedFormat('F Y'),
                    default => $d->translatedFormat('l, d F Y'),
                },
                'value' => 0,
                'count' => 0,
            ];
        }

        foreach (Transaction::query()->betweenDates($start, $end)->get(['created_at', 'total']) as $t) {
            $key = $bucketStart($t->created_at)->toDateString();
            if (isset($buckets[$key])) {
                $buckets[$key]['value'] += (int) round((float) $t->total);
                $buckets[$key]['count']++;
            }
        }

        return array_values($buckets);
    }

    /** Item terjual dari transaksi dalam rentang. */
    private function items(Carbon $start, Carbon $end): Builder
    {
        return TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<=', $end);
    }
}
