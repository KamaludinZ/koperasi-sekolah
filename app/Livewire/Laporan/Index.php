<?php

namespace App\Livewire\Laporan;

use App\Services\SalesExport;
use App\Services\SalesReport;
use Illuminate\Validation\ValidationException;
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

    /** Rentang kustom (Y-m-d) bila periode = kustom. */
    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    /** Rentang kustom terpanjang (hari). */
    public const MAX_DAYS = 366;

    public const EXPORT_SECTIONS = SalesExport::SECTIONS;

    public const EXPORT_FORMATS = SalesExport::FORMATS;

    /** Panel unduh: rentang (Y-m-d), format, dan bagian yang disertakan. */
    public string $exportFrom = '';

    public string $exportTo = '';

    public string $exportFormat = 'csv';

    public array $exportSections = SalesExport::DEFAULT_SECTIONS;

    public function mount(): void
    {
        if ($this->period === 'kustom' && ! $this->validCustom()) {
            $this->period = 'hari-ini';
        }
        $this->syncExportRange();
    }

    /** Terapkan rentang tanggal kustom (dari–sampai). */
    public function applyCustom(): void
    {
        $this->validate([
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today', function ($a, $v, $fail) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) && Carbon::parse($this->from)->diffInDays(Carbon::parse($v)) + 1 > self::MAX_DAYS) {
                    $fail('Rentang paling panjang '.self::MAX_DAYS.' hari.');
                }
            }],
        ], [
            'required' => 'Isi tanggal.',
            'to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'before_or_equal' => 'Tanggal tidak boleh melewati hari ini.',
        ]);
        $this->period = 'kustom';
        $this->resetComputed();
        $this->syncExportRange();
    }

    private function validCustom(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to)
            && $this->from <= $this->to && $this->to <= now()->toDateString();
    }

    private function resetComputed(): void
    {
        unset($this->range, $this->previousRange, $this->periodReport, $this->summary, $this->comparison, $this->topProducts, $this->discountReport, $this->soldProducts, $this->transactionsUrl);
    }

    /** Samakan rentang unduh dengan periode yang sedang dilihat. */
    public function syncExportRange(): void
    {
        [$start, $end] = $this->range;
        $this->exportFrom = $start->toDateString();
        $this->exportTo = $end->toDateString();
        $this->resetValidation(['exportFrom', 'exportTo']);
    }

    /** Unduh rekap sebagai CSV (dibuka langsung di Excel/Spreadsheet) atau PDF. */
    public function export()
    {
        $this->validate([
            'exportFrom' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'exportTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:exportFrom', 'before_or_equal:today'],
            'exportFormat' => ['required', 'in:'.implode(',', array_keys(self::EXPORT_FORMATS))],
            'exportSections' => ['required', 'array', 'min:1'],
            'exportSections.*' => ['in:'.implode(',', array_keys(self::EXPORT_SECTIONS))],
        ], [
            'exportTo.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'before_or_equal' => 'Tanggal tidak boleh melewati hari ini.',
            'exportSections.required' => 'Pilih minimal satu isi laporan.',
            'exportFormat.in' => 'Format ini belum tersedia.',
            'required' => 'Wajib diisi.',
        ]);

        try {
            return app(SalesExport::class)->download(
                Carbon::parse($this->exportFrom),
                Carbon::parse($this->exportTo),
                array_values($this->exportSections),
                $this->exportFormat,
            );
        } catch (ValidationException $e) {
            $map = ['sampai' => 'exportTo', 'isi' => 'exportSections'];
            foreach ($e->errors() as $key => $messages) {
                $this->addError($map[$key] ?? $key, $messages[0]);
            }

            return null;
        }
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::PERIODS)) {
            $this->period = $period;
            $this->from = $this->to = '';
            $this->resetValidation(['from', 'to']);
            $this->resetComputed();
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
    /** Dampak diskon & promo pada periode yang dilihat (DiscountReport). */
    #[Computed]
    public function discountReport(): array
    {
        [$start, $end] = $this->range;

        return app(\App\Services\DiscountReport::class)->report($start, $end);
    }

    #[Computed]
    public function range(): array
    {
        return $this->period === 'kustom' && $this->validCustom() ? SalesReport::customRanges($this->from, $this->to)[0] : SalesReport::range($this->period);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function previousRange(): array
    {
        return $this->period === 'kustom' && $this->validCustom() ? SalesReport::customRanges($this->from, $this->to)[1] : SalesReport::previousRange($this->period);
    }

    #[Computed]
    public function periodReport(): array
    {
        return $this->period === 'kustom' && $this->validCustom()
            ? app(SalesReport::class)->customReport($this->from, $this->to)
            : app(SalesReport::class)->periodReport($this->period);
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

    public const SOLD_SORTS = ['quantity' => 'Jumlah', 'revenue' => 'Omzet', 'profit' => 'Laba', 'name' => 'Nama'];

    /** Urutan tabel barang terjual. */
    public string $soldSort = 'quantity';

    public function sortSold(string $by): void
    {
        $this->soldSort = array_key_exists($by, self::SOLD_SORTS) ? $by : 'quantity';
        unset($this->soldProducts);
    }

    /** Semua barang terjual pada periode (bersih setelah retur), dengan margin. */
    #[Computed]
    public function soldProducts(): array
    {
        [$start, $end] = $this->range;
        $rows = collect(app(SalesReport::class)->topProducts($start, $end, null))
            ->map(fn ($p) => $p + ['margin' => $p['revenue'] > 0 ? (int) round($p['profit'] / $p['revenue'] * 100) : 0]);

        return ($this->soldSort === 'name' ? $rows->sortBy('name') : $rows->sortByDesc($this->soldSort))->values()->all();
    }

    /** Tautan Riwayat Transaksi untuk rentang yang sedang dilihat. */
    #[Computed]
    public function transactionsUrl(): string
    {
        [$start, $end] = $this->range;

        return route('transaksi', ['periode' => 'kustom', 'dari' => $start->toDateString(), 'sampai' => $end->toDateString()]);
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
