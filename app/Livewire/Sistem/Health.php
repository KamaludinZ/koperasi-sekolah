<?php

namespace App\Livewire\Sistem;

use App\Support\SystemHealth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sistem → Kesehatan (Admin): status keseluruhan, kartu pemeriksaan (basis data, cache, penyimpanan,
 * antrean, penjadwal, cadangan, aplikasi), angka ringkas, dan gangguan terbaru. Diperbarui otomatis
 * tiap 60 detik atau lewat tombol Periksa ulang. Data dari SystemHealth::report().
 */
#[Layout('components.layouts.app')]
#[Title('Kesehatan Sistem')]
class Health extends Component
{
    public const TONES = [
        'ok' => ['bg-emerald-50 border-emerald-200 text-emerald-900', 'bg-emerald-600'],
        'peringatan' => ['bg-amber-50 border-amber-200 text-amber-900', 'bg-amber-500'],
        'gangguan' => ['bg-red-50 border-red-200 text-red-900', 'bg-red-600'],
    ];

    #[Computed]
    public function report(): array
    {
        return SystemHealth::report();
    }

    public function recheck(): void
    {
        unset($this->report);
        $this->dispatch('toast', type: 'success', message: 'Pemeriksaan diperbarui.');
    }

    public function render()
    {
        return view('livewire.sistem.health');
    }
}
