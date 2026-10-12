<?php

namespace App\Livewire\Barang;

use App\Livewire\Forms\ProductForm;
use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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

    /** Filter status: '' (semua), 'menipis', 'habis', 'nonaktif'. */
    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public ProductForm $form;

    public bool $editing = false;

    public ?string $flash = null;

    public const STATUSES = [
        '' => 'Semua status',
        'menipis' => 'Stok menipis',
        'habis' => 'Stok habis',
        'nonaktif' => 'Nonaktif',
    ];

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
            ->filter(fn ($p) => match ($this->status) {
                'menipis' => $p['stock'] > 0 && $p['stock'] <= $p['min_stock'],
                'habis' => $p['stock'] <= 0,
                'nonaktif' => ! $p['is_active'],
                default => true,
            })
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

    /** Barang aktif yang perlu segera ditambah stoknya (habis + menipis). */
    #[Computed]
    public function lowStock(): array
    {
        return array_map(fn ($p) => [
            ...$p,
            // Saran restok: kembalikan stok ke dua kali batas minimum.
            'suggested' => max($p['min_stock'] * 2 - $p['stock'], 1),
        ], MockData::lowStockProducts());
    }

    #[Computed]
    public function stats(): array
    {
        $all = collect(MockData::products());

        return [
            'total' => $all->count(),
            'low' => $all->filter(fn ($p) => $p['stock'] > 0 && $p['stock'] <= $p['min_stock'])->count(),
            'out' => $all->filter(fn ($p) => $p['stock'] <= 0)->count(),
            'inactive' => $all->where('is_active', false)->count(),
            'stock_value' => $all->sum(fn ($p) => $p['cost_price'] * $p['stock']),
        ];
    }

    /** Klik kartu ringkasan: aktifkan filter status, klik lagi untuk melepas. */
    public function toggleStatus(string $status): void
    {
        $this->status = $this->status === $status ? '' : $status;
    }

    public function create(): void
    {
        $this->form->blank();
        $this->flash = null;
        $this->editing = true;
    }

    public function edit(int $productId): void
    {
        $product = MockData::findProduct($productId);
        if (! $product) {
            return;
        }

        $this->form->load($product);
        $this->flash = null;
        $this->editing = true;
    }

    public function save(): void
    {
        $isNew = $this->form->id === null;
        $product = $this->form->save();

        $this->editing = false;
        $this->flash = $isNew
            ? "{$product['name']} ditambahkan."
            : "Perubahan {$product['name']} disimpan.";
        unset($this->products, $this->stats, $this->lowStock);
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    #[On('min-stock-updated')]
    public function refreshAfterMinStock(string $message): void
    {
        $this->flash = $message;
        unset($this->products, $this->stats, $this->lowStock);
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'category', 'status', 'sort');
    }

    public function render()
    {
        return view('livewire.barang.index', [
            'categories' => MockData::categories(),
        ]);
    }
}
