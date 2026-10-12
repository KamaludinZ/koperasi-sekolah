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

    public const EXPORT_SECTIONS = SalesExport::SECTIONS;

    public const EXPORT_FORMATS = SalesExport::FORMATS;

    /** Panel unduh: rentang (Y-m-d), format, dan bagian yang disertakan. */
    public string $exportFrom = '';

    public string $exportTo = '';

    public string $exportFormat = 'csv';

    public array $exportSections = SalesExport::DEFAULT_SECTIONS;

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
