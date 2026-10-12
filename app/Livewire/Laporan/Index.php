<?php

namespace App\Livewire\Laporan;

use App\Services\ReceiptPresenter;
use App\Services\SalesReport;
use App\Services\TransactionHistory;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Laporan & Rekap untuk pengurus: hasil penjualan per periode dan barang terlaris,
 * grafik, dan unduhan rekap CSV. Angka dari SalesReport.
 */
#[Layout('components.layouts.app')]
#[Title('Laporan & Rekap')]
class Index extends Component
{
    public const PERIODS = SalesReport::PERIODS;

    public const CHART_PERIODS = SalesReport::CHART_PERIODS;

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

        $filename = 'rekap-penjualan-'.$start->format('Ymd').'-'.$end->format('Ymd').'.csv';
        $rows = $this->exportRows($start, $end);

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
    private function exportRows(Carbon $start, Carbon $end): array
    {
        $report = app(SalesReport::class);
        $rows = [
            ['Rekap Penjualan '.app(ReceiptPresenter::class)->profile()['name']],
            ['Periode', $start->translatedFormat('d F Y').' s.d. '.$end->translatedFormat('d F Y')],
            ['Dibuat', now()->translatedFormat('d F Y H:i')],
            [],
        ];
        $sections = $this->exportSections;

        if (in_array('summary', $sections, true)) {
            $s = $report->summary($start, $end);
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
            foreach ($report->daily($start, $end) as $day) {
                $rows[] = [$day['date'], $day['count'], $day['items'], $day['revenue']];
            }
            $rows[] = [];
        }

        if (in_array('transactions', $sections, true)) {
            $rows[] = ['RINCIAN TRANSAKSI'];
            $rows[] = ['Waktu', 'No. invoice', 'Kasir', 'Anggota', 'Metode', 'Barang', 'Total'];
            $query = app(TransactionHistory::class)->query(['from' => $start, 'to' => $end])->reorder()->orderBy('created_at')->orderBy('id');
            foreach ($query->lazy(500) as $t) {
                $r = TransactionHistory::toRow($t);
                $rows[] = [$r['created_at'], $r['invoice_number'], $r['cashier'], $r['member'] ?? '', $r['payment_method_label'], $r['item_count'], $r['total']];
            }
            $rows[] = [];
        }

        if (in_array('products', $sections, true)) {
            $rows[] = ['BARANG TERJUAL'];
            $rows[] = ['Barang', 'Jumlah', 'Penjualan', 'Laba kotor'];
            foreach ($report->topProducts($start, $end, null) as $p) {
                $rows[] = [$p['name'], $p['quantity'], $p['revenue'], $p['profit']];
            }
        }

        return $rows;
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::PERIODS)) {
            $this->period = $period;
            unset($this->range, $this->previousRange, $this->periodReport, $this->summary, $this->comparison, $this->topProducts);
            $this->syncExportRange();
        }
    }

    public function setChartPeriod(string $period): void
    {
        if (array_key_exists($period, self::CHART_PERIODS)) {
            $this->chartPeriod = $period;
        }
    }

    /** @return list<array{date: string, label: string, tooltip: string, value: int, count: int}> */
    #[Computed]
    public function chartPoints(): array
    {
        return app(SalesReport::class)->chartPoints($this->chartPeriod);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function range(): array
    {
        return SalesReport::range($this->period);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function previousRange(): array
    {
        return SalesReport::previousRange($this->period);
    }

    #[Computed]
    public function periodReport(): array
    {
        return app(SalesReport::class)->periodReport($this->period);
    }

    #[Computed]
    public function summary(): array
    {
        return $this->periodReport['summary'];
    }

    /** Ringkasan periode sebelumnya + persentase perubahan tiap angka. */
    #[Computed]
    public function comparison(): array
    {
        return $this->periodReport['comparison'];
    }

    /** Ringkasan hari ini (tidak bergantung pilihan periode). */
    #[Computed]
    public function today(): array
    {
        return app(SalesReport::class)->today();
    }

    /** Barang terlaris pada periode (berdasarkan jumlah terjual). */
    #[Computed]
    public function topProducts(): array
    {
        [$start, $end] = $this->range;

        return app(SalesReport::class)->topProducts($start, $end, 5);
    }

    public function render()
    {
        return view('livewire.laporan.index');
    }
}
