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
        $this->type = $this->type === 'tahun' ? 'tahun' : 'bulan';
        $this->month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) && $this->month <= today()->format('Y-m') ? $this->month : '';
        $this->year = preg_match('/^\d{4}$/', $this->year) && $this->year >= '2000' && $this->year <= today()->format('Y') ? $this->year : '';
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->sanitize();
    }

    /** Geser periode: -1 sebelumnya, +1 berikutnya (tidak melewati periode berjalan). */
    public function shift(int $step): void
    {
        $step = $step < 0 ? -1 : 1;
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
        return $this->type === 'tahun' ? ($this->year === '' || $this->year === today()->format('Y')) : ($this->month === '' || $this->month === today()->format('Y-m'));
    }

    public function render()
    {
        return view('livewire.pinjaman.recap');
    }
}
