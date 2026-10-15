<?php

namespace App\Livewire\Pinjaman;

use App\Services\LoanRecapService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pinjaman → Rekap (Admin & Pengurus): rekap per bulan atau per tahun — pengajuan, pencairan, angsuran diterima
 * (per metode, pokok/bunga), posisi portofolio, dan rincian bulanan untuk rekap tahunan.
 */
#[Layout('components.layouts.app')]
#[Title('Rekap Pinjaman')]
class Recap extends Component
{
    #[Url(as: 'jenis', except: 'bulan')]
    public string $type = 'bulan';

    #[Url(as: 'bulan', except: '')]
    public string $month = '';

    #[Url(as: 'tahun', except: '')]
    public string $year = '';

    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    /** Pintasan periode. */
    public const PRESETS = ['bulan-ini' => 'Bulan ini', 'bulan-lalu' => 'Bulan lalu', '3-bulan' => '3 bulan terakhir', 'tahun-ini' => 'Tahun ini', 'tahun-lalu' => 'Tahun lalu'];

    public function mount(): void
    {
        $this->sanitize();
    }

    public function updated(): void
    {
        $this->sanitize();
    }

    private function sanitize(): void
    {
        $this->type = in_array($this->type, ['bulan', 'tahun', 'rentang'], true) ? $this->type : 'bulan';
        if ($this->type === 'rentang' && ! LoanRecapService::range($this->from, $this->to)) {
            // rentang tidak sah → tetap mode rentang dengan bawaan 30 hari terakhir
            [$this->from, $this->to] = [today()->subDays(29)->toDateString(), today()->toDateString()];
        }
        if ($this->type !== 'rentang') {
            [$this->from, $this->to] = ['', ''];
        }
        $this->month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) && $this->month <= today()->format('Y-m') ? $this->month : '';
        $this->year = preg_match('/^\d{4}$/', $this->year) && $this->year >= '2000' && $this->year <= today()->format('Y') ? $this->year : '';
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->sanitize();
    }

    public function preset(string $name): void
    {
        $today = today();
        match ($name) {
            'bulan-ini' => [$this->type, $this->month] = ['bulan', ''],
            'bulan-lalu' => [$this->type, $this->month] = ['bulan', $today->copy()->startOfMonth()->subMonth()->format('Y-m')],
            '3-bulan' => [$this->type, $this->from, $this->to] = ['rentang', $today->copy()->startOfMonth()->subMonths(2)->toDateString(), $today->toDateString()],
            'tahun-ini' => [$this->type, $this->year] = ['tahun', ''],
            'tahun-lalu' => [$this->type, $this->year] = ['tahun', (string) ($today->year - 1)],
            default => null,
        };
        $this->sanitize();
    }

    /** Geser periode: -1 sebelumnya, +1 berikutnya (tidak melewati periode berjalan). */
    public function shift(int $step): void
    {
        $step = $step < 0 ? -1 : 1;
        if ($this->type === 'rentang') {
            return; // rentang bebas diubah lewat isian tanggal
        }
        if ($this->type === 'tahun') {
            $this->year = (string) ((int) ($this->year ?: today()->format('Y')) + $step);
        } else {
            $this->month = \Illuminate\Support\Carbon::parse(($this->month ?: today()->format('Y-m')).'-01')->addMonthsNoOverflow($step)->format('Y-m');
        }
        $this->sanitize();
    }

    #[Computed]
    public function period(): array
    {
        if ($this->type === 'rentang') {
            return LoanRecapService::range($this->from, $this->to);
        }

        return LoanRecapService::period($this->type, $this->type === 'tahun' ? ($this->year ?: today()->format('Y')) : ($this->month ?: today()->format('Y-m')));
    }

    #[Computed]
    public function recap(): array
    {
        [$from, $to] = $this->period;

        return app(LoanRecapService::class)->recap($from, $to);
    }

    #[Computed]
    public function isCurrent(): bool
    {
        if ($this->type === 'rentang') {
            return true;
        }

        return $this->type === 'tahun' ? ($this->year === '' || $this->year === today()->format('Y')) : ($this->month === '' || $this->month === today()->format('Y-m'));
    }

    /** Minta ekspor CSV periode yang sedang dilihat ke antrean latar. */
    public function export(\App\Services\ReportExportService $exports): void
    {
        [$from, $to] = $this->period;
        try {
            $exports->requestLoanRecap(auth()->user(), $from, $to);
            unset($this->exports);
            $this->dispatch('toast', type: 'success', message: 'Ekspor CSV sedang disiapkan.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: collect($e->errors())->flatten()->first());
        }
    }

    /** Ekspor rekap pinjaman milik petugas ini (terbaru dulu) beserta penanda unduhan. */
    #[Computed]
    public function exports(): array
    {
        return app(\App\Services\ReportExportService::class)->recent(auth()->user(), 'pinjaman_rekap', 5)
            ->map(fn ($e) => \App\Http\Controllers\Pinjaman\RecapExportController::toArray($e))->all();
    }

    public function render()
    {
        return view('livewire.pinjaman.recap');
    }
}
