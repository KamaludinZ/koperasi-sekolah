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

    #[Url(as: 'periode', except: 'hari-ini')]
    public string $period = 'hari-ini';

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::PERIODS)) {
            $this->period = $period;
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function range(): array
    {
        $now = now();

        return match ($this->period) {
            '7-hari' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30-hari' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'bulan-ini' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    #[Computed]
    public function transactions(): array
    {
        [$start, $end] = $this->range;

        return array_values(array_filter(MockData::transactions(30), function ($t) use ($start, $end) {
            $at = Carbon::parse($t['created_at']);

            return $at->gte($start) && $at->lte($end);
        }));
    }

    #[Computed]
    public function summary(): array
    {
        $tx = collect($this->transactions);
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
