<?php

namespace App\Livewire\Barang;

use App\Services\RestockAdvisor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Barang → Saran restok: barang yang perlu dipesan berdasarkan kecepatan penjualan 30 hari (rata-rata per
 * hari, perkiraan habis), jumlah saran untuk 1 minggu/2 minggu/1 bulan ke depan ditambah batas minimum,
 * perkiraan biaya, tautan ke pesanan pembelian dan pengaturan batas minimum. Data dari RestockAdvisor.
 */
#[Layout('components.layouts.app')]
#[Title('Saran Restok')]
class Restock extends Component
{
    public const LEVELS = ['habis' => ['Habis', 'bg-red-100 text-red-800'], 'segera' => ['Segera pesan', 'bg-amber-100 text-amber-900'],
        'rencana' => ['Rencanakan', 'bg-sky-100 text-sky-800'], 'aman' => ['Aman', 'bg-emerald-100 text-emerald-800']];

    #[Url(as: 'untuk', except: 14)]
    public int $horizon = 14;

    #[Url(as: 'semua', except: false)]
    public bool $showAll = false;

    public function setHorizon(int $days): void
    {
        $this->horizon = array_key_exists($days, RestockAdvisor::HORIZONS) ? $days : 14;
    }

    #[Computed]
    public function rows(): array
    {
        return app(RestockAdvisor::class)->suggestions($this->horizon, $this->showAll);
    }

    #[Computed]
    public function totals(): array
    {
        $c = collect($this->rows);

        return [
            'out' => $c->where('level', 'habis')->count(),
            'urgent' => $c->where('level', 'segera')->count(),
            'units' => (int) $c->sum('suggested'),
            'cost' => (int) $c->sum(fn ($r) => $r['suggested'] * $r['cost']),
        ];
    }

    public function render()
    {
        return view('livewire.barang.restock');
    }
}
