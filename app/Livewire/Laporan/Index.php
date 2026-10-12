<?php

namespace App\Livewire\Laporan;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Laporan & Rekap untuk pengurus: hasil penjualan per periode dan barang terlaris.
 * Tahap frontend: dihitung dari transaksi tiruan (MockData::transactions).
 */
#[Layout('components.layouts.app')]
#[Title('Laporan & Rekap')]
class Index extends Component
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

    #[Url(as: 'grafik', except: '7-hari')]
    public string $chartPeriod = '7-hari';

    #[Url(as: 'periode', except: 'hari-ini')]
    public string $period = 'hari-ini';

    public const EXPORT_SECTIONS = [
        'summary' => 'Ringkasan penjualan',
        'daily' => 'Rekap per hari',
        'transactions' => 'Rincian transaksi',
        'products' => 'Barang terjual',
    ];

    /** Panel unduh: rentang (Y-m-d), format, dan bagian yang disertakan. */
    public string $exportFrom = '';

    public string $exportTo = '';

    public string $exportFormat = 'csv';

    public array $exportSections = ['summary', 'daily', 'products'];

    public function mount(): void
    {
        $this->syncExportRange();
    }

    /** Samakan rentang unduh dengan periode yang sedang dilihat. */
    public function syncExportRange(): void
    {
        [$start, $end] = $this->range;
        $this->exportFrom = $start->toDateString();
        $this->exportTo = $end->toDateString();
        $this->resetValidation(['exportFrom', 'exportTo']);
    }

    /** Unduh rekap sebagai CSV (dibuka langsung di Excel/Spreadsheet). */
    public function export()
    {
        $this->validate([
            'exportFrom' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'exportTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:exportFrom', 'before_or_equal:today'],
            'exportFormat' => ['required', 'in:csv'],
            'exportSections' => ['required', 'array', 'min:1'],
            'exportSections.*' => ['in:'.implode(',', array_keys(self::EXPORT_SECTIONS))],
        ], [
            'exportTo.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'before_or_equal' => 'Tanggal tidak boleh melewati hari ini.',
            'exportSections.required' => 'Pilih minimal satu isi laporan.',
            'exportFormat.in' => 'Format ini belum tersedia.',
            'required' => 'Wajib diisi.',
        ]);

        $start = Carbon::parse($this->exportFrom)->startOfDay();
        $end = Carbon::parse($this->exportTo)->endOfDay();
        if ($start->diffInDays($end) > 366) {
            $this->addError('exportTo', 'Rentang maksimal 1 tahun.');

            return null;
        }

        $transactions = $this->between($start, $end);
        $filename = 'rekap-penjualan-'.$start->format('Ymd').'-'.$end->format('Ymd').'.csv';
        $rows = $this->exportRows($transactions, $start, $end);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Susun baris CSV sesuai bagian yang dipilih. */
    private function exportRows(array $transactions, Carbon $start, Carbon $end): array
    {
        $profile = \App\Services\ReceiptPresenter::class;
        $rows = [
            ['Rekap Penjualan '.app($profile)->profile()['name']],
            ['Periode', $start->translatedFormat('d F Y').' s.d. '.$end->translatedFormat('d F Y')],
            ['Dibuat', now()->translatedFormat('d F Y H:i')],
            [],
        ];
        $sections = $this->exportSections;

        if (in_array('summary', $sections, true)) {
            $s = $this->summarize($transactions);
            $rows[] = ['RINGKASAN'];
            $rows[] = ['Total penjualan', $s['revenue']];
            $rows[] = ['Laba kotor', $s['gross_profit']];
            $rows[] = ['Margin (%)', $s['margin']];
            $rows[] = ['Jumlah transaksi', $s['count']];
            $rows[] = ['Barang terjual', $s['items']];
            $rows[] = ['Rata-rata per transaksi', $s['average']];
            $rows[] = [];
        }

        if (in_array('daily', $sections, true)) {
            $rows[] = ['REKAP PER HARI'];
            $rows[] = ['Tanggal', 'Transaksi', 'Barang terjual', 'Penjualan'];
            foreach (collect($transactions)->groupBy(fn ($t) => substr($t['created_at'], 0, 10))->sortKeys() as $date => $day) {
                $rows[] = [$date, $day->count(), $day->sum('item_count'), $day->sum('total')];
            }
            $rows[] = [];
        }

        if (in_array('transactions', $sections, true)) {
            $rows[] = ['RINCIAN TRANSAKSI'];
            $rows[] = ['Waktu', 'No. invoice', 'Kasir', 'Metode', 'Barang', 'Total'];
            foreach (collect($transactions)->sortBy('created_at') as $t) {
                $rows[] = [$t['created_at'], $t['invoice_number'], $t['cashier'], $t['payment_method'], $t['item_count'], $t['total']];
            }
            $rows[] = [];
        }

        if (in_array('products', $sections, true)) {
            $rows[] = ['BARANG TERJUAL'];
            $rows[] = ['Barang', 'Jumlah', 'Penjualan'];
            $items = collect($transactions)->flatMap(fn ($t) => $t['items'])->groupBy('product_name')
                ->map(fn ($g, $name) => [$name, $g->sum('quantity'), $g->sum('subtotal')])
                ->sortByDesc(fn ($r) => $r[1]);
            foreach ($items as $r) {
                $rows[] = $r;
            }
        }

        return $rows;
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::PERIODS)) {
            $this->period = $period;
            unset($this->range);
            $this->syncExportRange();
        }
    }

    public function setChartPeriod(string $period): void
    {
        if (array_key_exists($period, self::CHART_PERIODS)) {
            $this->chartPeriod = $period;
        }
    }

    /**
     * Titik grafik penjualan: per hari (7/30 hari) atau per minggu (12 minggu),
     * termasuk periode tanpa penjualan (nilai 0) agar sumbu waktu tidak bolong.
     *
     * @return list<array{label: string, tooltip: string, value: int, count: int}>
     */
    #[Computed]
    public function chartPoints(): array
    {
        $now = now();
        $weekly = $this->chartPeriod === '12-minggu';
        $start = match ($this->chartPeriod) {
            '30-hari' => $now->copy()->subDays(29)->startOfDay(),
            '12-minggu' => $now->copy()->startOfWeek()->subWeeks(11),
            default => $now->copy()->subDays(6)->startOfDay(),
        };

        $buckets = [];
        for ($d = $start->copy(); $d->lte($now); $weekly ? $d->addWeek() : $d->addDay()) {
            $key = $d->toDateString();
            $buckets[$key] = [
                'label' => $weekly ? $d->translatedFormat('d M') : ($this->chartPeriod === '7-hari' ? $d->translatedFormat('D d') : $d->translatedFormat('d/m')),
                'tooltip' => $weekly
                    ? 'Minggu '.$d->translatedFormat('d M').' – '.$d->copy()->endOfWeek()->translatedFormat('d M Y')
                    : $d->translatedFormat('l, d F Y'),
                'value' => 0,
                'count' => 0,
            ];
        }

        foreach (MockData::transactions(90) as $t) {
            $at = Carbon::parse($t['created_at']);
            if ($at->lt($start)) {
                continue;
            }
            $key = ($weekly ? $at->copy()->startOfWeek() : $at)->toDateString();
            if (isset($buckets[$key])) {
                $buckets[$key]['value'] += $t['total'];
                $buckets[$key]['count']++;
            }
        }

        return array_values($buckets);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function range(): array
    {
        $now = now();

        return match ($this->period) {
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
    #[Computed]
    public function previousRange(): array
    {
        [$start, $end] = $this->range;

        return match ($this->period) {
            '7-hari' => [$start->copy()->subDays(7), $end->copy()->subDays(7)],
            '30-hari' => [$start->copy()->subDays(30), $end->copy()->subDays(30)],
            'bulan-ini' => [$start->copy()->subMonthNoOverflow(), $end->copy()->subMonthNoOverflow()],
            default => [$start->copy()->subDay(), $end->copy()->subDay()],
        };
    }

    /** Ringkasan periode sebelumnya + persentase perubahan tiap angka. */
    #[Computed]
    public function comparison(): array
    {
        [$start, $end] = $this->previousRange;
        $previous = $this->summarize($this->between($start, $end));
        $current = $this->summary;

        $changes = [];
        foreach (['revenue', 'gross_profit', 'count', 'items', 'average'] as $key) {
            $changes[$key] = $previous[$key] > 0
                ? (int) round(($current[$key] - $previous[$key]) / $previous[$key] * 100)
                : null;
        }

        return [
            'previous' => $previous,
            'changes' => $changes,
            'label' => match ($this->period) {
                '7-hari' => '7 hari sebelumnya',
                '30-hari' => '30 hari sebelumnya',
                'bulan-ini' => 'periode sama bulan lalu',
                default => 'kemarin jam segini',
            },
            'range_label' => $start->isSameDay($end)
                ? $start->translatedFormat('d M Y').' s.d. '.$end->format('H:i')
                : $start->translatedFormat('d M').' – '.$end->translatedFormat('d M Y'),
        ];
    }

    #[Computed]
    public function transactions(): array
    {
        [$start, $end] = $this->range;

        return $this->between($start, $end);
    }

    private function between(Carbon $start, Carbon $end): array
    {
        return array_values(array_filter(MockData::transactions(90), function ($t) use ($start, $end) {
            $at = Carbon::parse($t['created_at']);

            return $at->gte($start) && $at->lte($end);
        }));
    }

    #[Computed]
    public function summary(): array
    {
        return $this->summarize($this->transactions);
    }

    private function summarize(array $transactions): array
    {
        $tx = collect($transactions);
        $costs = collect(MockData::seedProducts())->pluck('cost_price', 'name');
        $revenue = $tx->sum('total');
        $cost = $tx->flatMap(fn ($t) => $t['items'])->sum(fn ($i) => ($costs[$i['product_name']] ?? 0) * $i['quantity']);

        return [
            'revenue' => $revenue,
            'count' => $tx->count(),
            'items' => $tx->sum('item_count'),
            'gross_profit' => $revenue - $cost,
            'margin' => $revenue > 0 ? (int) round(($revenue - $cost) / $revenue * 100) : 0,
            'average' => $tx->count() ? (int) round($revenue / $tx->count()) : 0,
        ];
    }

    /**
     * Ringkasan hari ini (tidak bergantung pilihan periode), dibandingkan dengan
     * kemarin sampai jam yang sama, plus penjualan per jam untuk grafik mini.
     */
    #[Computed]
    public function today(): array
    {
        $now = now();
        $all = collect(MockData::transactions(30));
        $today = $all->filter(fn ($t) => Carbon::parse($t['created_at'])->isSameDay($now));
        $yesterdaySoFar = $all->filter(function ($t) use ($now) {
            $at = Carbon::parse($t['created_at']);

            return $at->isSameDay($now->copy()->subDay()) && $at->format('H:i:s') <= $now->format('H:i:s');
        });

        $revenue = $today->sum('total');
        $before = $yesterdaySoFar->sum('total');

        $best = $today->flatMap(fn ($t) => $t['items'])->groupBy('product_name')
            ->map(fn ($items) => $items->sum('quantity'))->sortDesc();

        // Penjualan per jam jam operasional 07–15.
        $hourly = collect(range(7, 15))->mapWithKeys(fn ($h) => [$h => 0])->all();
        foreach ($today as $t) {
            $h = (int) substr($t['created_at'], 11, 2);
            if (array_key_exists($h, $hourly)) {
                $hourly[$h] += $t['total'];
            }
        }

        return [
            'revenue' => $revenue,
            'count' => $today->count(),
            'items' => $today->sum('item_count'),
            'compare' => $before,
            'change' => $before > 0 ? (int) round(($revenue - $before) / $before * 100) : null,
            'best' => $best->isEmpty() ? null : ['name' => $best->keys()->first(), 'quantity' => $best->first()],
            'last_at' => $today->max('created_at'),
            'hourly' => $hourly,
            'current_hour' => (int) $now->format('G'),
        ];
    }

    /** Barang terlaris pada periode (berdasarkan jumlah terjual). */
    #[Computed]
    public function topProducts(): array
    {
        return collect($this->transactions)
            ->flatMap(fn ($t) => $t['items'])
            ->groupBy('product_name')
            ->map(fn ($items, $name) => [
                'name' => $name,
                'quantity' => $items->sum('quantity'),
                'revenue' => $items->sum('subtotal'),
            ])
            ->sortByDesc('quantity')
            ->take(5)
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.laporan.index');
    }
}
