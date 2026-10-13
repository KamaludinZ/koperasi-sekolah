<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Agregasi laporan penjualan untuk pengurus: ringkasan periode (omzet, laba kotor,
 * margin, transaksi, barang, rata-rata) + pembanding periode setara, ringkasan hari ini,
 * barang terlaris, rekap per hari, dan titik grafik per hari/minggu.
 * Laba kotor memakai snapshot harga modal item (transaction_items.cost_price).
 * Angka bersih: transaksi yang dibatalkan tidak dihitung; nilai, jumlah barang, dan modal barang
 * retur yang disetujui dikurangkan (modal barang rusak tetap menjadi beban).
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
        return \App\Support\ReportCache::remember('summary', [$start, $end], fn () => $this->computeSummary($start, $end));
    }

        private function computeSummary(Carbon $start, Carbon $end): array
    {
        // Penjualan bersih: transaksi batal tidak dihitung; nilai & barang retur yang disetujui dikurangkan.
        $tx = Transaction::query()->betweenDates($start, $end)->notVoided()->toBase()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total - returned_amount), 0) AS revenue, COALESCE(SUM(item_count), 0) AS items')
            ->first();
        $cost = (float) $this->items($start, $end)->toBase()
            ->selectRaw('COALESCE(SUM(transaction_items.cost_price * transaction_items.quantity), 0) AS cost')
            ->value('cost');
        $returned = $this->returnedTotals($start, $end);

        $count = (int) $tx->n;
        $revenue = (int) round((float) $tx->revenue);
        $profit = $revenue - (int) round($cost - $returned['cost']);

        return [
            'revenue' => $revenue,
            'count' => $count,
            'items' => max(0, (int) $tx->items - $returned['quantity']),
            'gross_profit' => $profit,
            'margin' => $revenue > 0 ? (int) round($profit / $revenue * 100) : 0,
            'average' => $count ? (int) round($revenue / $count) : 0,
        ];
    }

    /**
     * Rentang kustom (tanggal Y-m-d) dan pembandingnya: rentang sama panjang tepat sebelumnya.
     *
     * @return array{0: array{0: Carbon, 1: Carbon}, 1: array{0: Carbon, 1: Carbon}}
     */
    public static function customRanges(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->isToday() ? now() : Carbon::parse($to)->endOfDay();
        $days = (int) $start->diffInDays(Carbon::parse($to)->startOfDay()) + 1;

        return [[$start, $end], [$start->copy()->subDays($days), $start->copy()->subSecond()]];
    }

    /** Ringkasan rentang kustom + pembanding rentang sama panjang sebelumnya. */
    public function customReport(string $from, string $to): array
    {
        [[$start, $end], [$prevStart, $prevEnd]] = self::customRanges($from, $to);
        return $this->compare($start, $end, $prevStart, $prevEnd, ((int) $prevStart->diffInDays($start)).' hari sebelumnya');
    }

    /** Ringkasan periode + periode pembanding dan persentase perubahan tiap angka. */
    public function periodReport(string $period): array
    {
        [$start, $end] = self::range($period);
        [$prevStart, $prevEnd] = self::previousRange($period);

        return $this->compare($start, $end, $prevStart, $prevEnd, match ($period) {
            '7-hari' => '7 hari sebelumnya',
            '30-hari' => '30 hari sebelumnya',
            'bulan-ini' => 'periode sama bulan lalu',
            default => 'kemarin jam segini',
        });
    }

    private function compare(Carbon $start, Carbon $end, Carbon $prevStart, Carbon $prevEnd, string $label): array
    {
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
                'label' => $label,
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
        return \App\Support\ReportCache::remember('today', [now()], fn () => $this->computeToday(), 60);
    }

        private function computeToday(): array
    {
        $now = now();
        $start = $now->copy()->startOfDay();
        $today = $this->summary($start, $now);
        $before = $this->summary($start->copy()->subDay(), $now->copy()->subDay())['revenue'];
        $best = $this->topProducts($start, $now, 1)[0] ?? null;

        [$open, $close] = self::OPEN_HOURS;
        $hourly = collect(range($open, $close))->mapWithKeys(fn ($h) => [$h => 0])->all();
        $rows = Transaction::query()->betweenDates($start, $now)->notVoided()->get(['created_at', 'total', 'returned_amount']);
        foreach ($rows as $t) {
            $h = (int) $t->created_at->format('G');
            if (array_key_exists($h, $hourly)) {
                $hourly[$h] += (int) round((float) $t->total) - $t->returned_amount;
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
        return \App\Support\ReportCache::remember('top', [$start, $end, $limit], fn () => $this->computeTopProducts($start, $end, $limit));
    }

        private function computeTopProducts(Carbon $start, Carbon $end, ?int $limit = 5): array
    {
        $returned = $this->returnedByProduct($start, $end);

        $rows = collect($this->items($start, $end)->toBase()
            ->groupBy('transaction_items.product_name')
            ->selectRaw('transaction_items.product_name AS name, SUM(transaction_items.quantity) AS quantity,
                SUM(transaction_items.subtotal) AS revenue,
                SUM(transaction_items.subtotal - transaction_items.cost_price * transaction_items.quantity) AS profit')
            ->get())
            ->map(function ($r) use ($returned) {
                $back = $returned[$r->name] ?? ['quantity' => 0, 'amount' => 0, 'cost' => 0];

                return [
                    'name' => $r->name,
                    'quantity' => (int) $r->quantity - $back['quantity'],
                    'revenue' => (int) round((float) $r->revenue) - $back['amount'],
                    'profit' => (int) round((float) $r->profit) - $back['amount'] + $back['cost'],
                ];
            })
            ->filter(fn ($r) => $r['quantity'] > 0)
            ->sortBy([['quantity', 'desc'], ['name', 'asc']])
            ->values();

        return ($limit ? $rows->take($limit) : $rows)->all();
    }

    /**
     * Rekap per tanggal (lama → baru), hanya tanggal yang ada penjualan.
     *
     * @return list<array{date: string, count: int, items: int, revenue: int}>
     */
    public function daily(Carbon $start, Carbon $end): array
    {
        return \App\Support\ReportCache::remember('daily', [$start, $end], fn () => $this->computeDaily($start, $end));
    }

        private function computeDaily(Carbon $start, Carbon $end): array
    {
        return Transaction::query()->betweenDates($start, $end)->notVoided()
            ->get(['created_at', 'total', 'item_count', 'returned_amount'])
            ->groupBy(fn ($t) => $t->created_at->toDateString())
            ->sortKeys()
            ->map(fn ($day, $date) => [
                'date' => $date,
                'count' => $day->count(),
                'items' => (int) $day->sum('item_count'),
                'revenue' => (int) round((float) $day->sum('total')) - (int) $day->sum('returned_amount'),
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
        return \App\Support\ReportCache::remember('chart', [$start, $end, $unit, $dayLabel], fn () => $this->computeChart($start, $end, $unit, $dayLabel));
    }

        private function computeChart(Carbon $start, Carbon $end, string $unit = 'hari', ?string $dayLabel = null): array
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

        foreach (Transaction::query()->betweenDates($start, $end)->notVoided()->get(['created_at', 'total', 'returned_amount']) as $t) {
            $key = $bucketStart($t->created_at)->toDateString();
            if (isset($buckets[$key])) {
                $buckets[$key]['value'] += (int) round((float) $t->total) - $t->returned_amount;
                $buckets[$key]['count']++;
            }
        }

        return array_values($buckets);
    }

    /** Item terjual dari transaksi (tidak batal) dalam rentang. */
    private function items(Carbon $start, Carbon $end): Builder
    {
        return TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<=', $end)
            ->whereNull('transactions.voided_at');
    }

    /**
     * Barang retur yang disetujui dari transaksi (tidak batal) dalam rentang, per nama barang:
     * jumlah, nilai bersih dikembalikan, dan modal yang kembali (hanya barang yang masuk stok lagi).
     *
     * @return array<string, array{quantity: int, amount: int, cost: int}>
     */
    private function returnedByProduct(Carbon $start, Carbon $end): array
    {
        return DB::table('transaction_return_items as ri')
            ->join('transaction_returns as r', 'r.id', '=', 'ri.transaction_return_id')
            ->join('transaction_items as ti', 'ti.id', '=', 'ri.transaction_item_id')
            ->join('transactions as t', 't.id', '=', 'r.transaction_id')
            ->where('r.status', 'disetujui')->where('r.kind', 'retur')
            ->whereNull('t.voided_at')
            ->where('t.created_at', '>=', $start)->where('t.created_at', '<=', $end)
            ->groupBy('ri.product_name')
            ->selectRaw('ri.product_name AS name, SUM(ri.quantity) AS quantity, SUM(ri.amount) AS amount,
                SUM(CASE WHEN r.restock THEN ti.cost_price * ri.quantity ELSE 0 END) AS cost')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->name => ['quantity' => (int) $r->quantity, 'amount' => (int) $r->amount, 'cost' => (int) round((float) $r->cost)]])
            ->all();
    }

    /** @return array{quantity: int, amount: int, cost: int} total barang retur dalam rentang */
    private function returnedTotals(Carbon $start, Carbon $end): array
    {
        $all = collect($this->returnedByProduct($start, $end));

        return ['quantity' => (int) $all->sum('quantity'), 'amount' => (int) $all->sum('amount'), 'cost' => (int) $all->sum('cost')];
    }
}
