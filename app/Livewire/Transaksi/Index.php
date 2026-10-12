<?php

namespace App\Livewire\Transaksi;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Riwayat transaksi: semua penjualan dari terbaru ke lama, dikelompokkan per hari.
 * Tahap frontend: memakai data tiruan (MockData::transactions).
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Transaksi')]
class Index extends Component
{
    #[Computed]
    public function transactions(): array
    {
        return MockData::transactions();
    }

    /** Transaksi dikelompokkan per tanggal (Y-m-d), terbaru dulu, beserta subtotal harian. */
    #[Computed]
    public function days(): array
    {
        return collect($this->transactions)
            ->groupBy(fn ($t) => substr($t['created_at'], 0, 10))
            ->map(fn ($rows, $date) => [
                'date' => Carbon::parse($date),
                'rows' => $rows->all(),
                'count' => $rows->count(),
                'total' => $rows->sum('total'),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->transactions);

        return [
            'count' => $all->count(),
            'total' => $all->sum('total'),
            'items' => $all->sum('item_count'),
            'average' => $all->count() ? (int) round($all->avg('total')) : 0,
        ];
    }

    public function render()
    {
        return view('livewire.transaksi.index');
    }
}
