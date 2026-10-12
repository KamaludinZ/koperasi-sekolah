<?php

namespace App\Livewire\Barang;

use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Halaman daftar barang: semua barang beserta harga dan sisa stok.
 * Masih memakai data tiruan sampai tabel products tersedia.
 */
#[Layout('components.layouts.app')]
#[Title('Barang & Stok')]
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public const SORTS = [
        'name' => 'Nama (A–Z)',
        'stock' => 'Stok paling sedikit',
        'price' => 'Harga termurah',
        'price_desc' => 'Harga termahal',
    ];

    #[Computed]
    public function products(): array
    {
        $term = mb_strtolower(trim($this->search));

        $items = collect(MockData::products())
            ->filter(fn ($p) => $this->category === '' || $p['category'] === $this->category)
            ->filter(fn ($p) => $term === ''
                || str_contains(mb_strtolower($p['name']), $term)
                || str_contains(mb_strtolower($p['code']), $term));

        $items = match ($this->sort) {
            'stock' => $items->sortBy('stock'),
            'price' => $items->sortBy('price'),
            'price_desc' => $items->sortByDesc('price'),
            default => $items->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE),
        };

        return $items->values()->all();
    }

    #[Computed]
    public function stats(): array
    {
        $all = collect(MockData::products());

        return [
            'total' => $all->count(),
            'low' => $all->filter(fn ($p) => $p['stock'] > 0 && $p['stock'] <= $p['min_stock'])->count(),
            'out' => $all->where('stock', 0)->count(),
            'stock_value' => $all->sum(fn ($p) => $p['cost_price'] * $p['stock']),
        ];
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'category', 'sort');
    }

    public function render()
    {
        return view('livewire.barang.index', [
            'categories' => MockData::categories(),
        ]);
    }
}
