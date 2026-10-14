<?php

namespace App\Livewire\Barang;

use App\Models\Product;
use App\Services\MinStockService;
use App\Services\ProductCatalog;
use App\Services\ProductListing;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dialog pengaturan batas minimum stok: ubah banyak barang sekaligus,
 * terapkan satu nilai ke satu kategori, dan atur batas bawaan barang baru.
 * Mengirim event `min-stock-updated` agar daftar barang memuat ulang.
 */
class MinStockSettings extends Component
{
    public bool $open = false;

    /** @var array<int, int|string|null> [productId => min_stock] */
    public array $minStocks = [];

    public ?int $defaultMinStock = null;

    public string $bulkCategory = '';

    public ?int $bulkValue = null;

    public string $filter = '';

    #[On('open-min-stock-settings')]
    public function show(): void
    {
        abort_unless(Gate::allows('kelola-katalog'), 403);
        $this->resetValidation();
        $this->minStocks = Product::pluck('min_stock', 'id')->all();
        $this->defaultMinStock = app(MinStockService::class)->defaultMinStock();
        $this->reset('bulkCategory', 'bulkValue', 'filter');
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** Isi nilai yang sama ke semua barang dalam kategori (atau semua barang). Belum disimpan. */
    public function applyBulk(): void
    {
        $this->validate(['bulkValue' => ['required', 'integer', 'min:0']], [
            'bulkValue.required' => 'Isi angka batasnya dulu.',
            'bulkValue.min' => 'Tidak boleh negatif.',
        ]);

        $ids = Product::query()
            ->when($this->bulkCategory !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('name', $this->bulkCategory)))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->minStocks[$id] = $this->bulkValue;
        }
    }

    public function save(): void
    {
        abort_unless(Gate::allows('kelola-katalog'), 403);
        $this->validate([
            'minStocks' => ['array'],
            'minStocks.*' => ['required', 'integer', 'min:0', 'max:100000'],
            'defaultMinStock' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [
            'minStocks.*.required' => 'Wajib diisi.',
            'minStocks.*.integer' => 'Harus angka.',
            'minStocks.*.min' => 'Min. 0.',
            'defaultMinStock.required' => 'Wajib diisi.',
            'defaultMinStock.min' => 'Tidak boleh negatif.',
        ]);

        $service = app(MinStockService::class);
        $changed = $service->updateMany(array_map('intval', $this->minStocks));
        $service->setDefaultMinStock($this->defaultMinStock);

        $this->open = false;
        $this->dispatch('min-stock-updated', message: $changed > 0
            ? "Batas minimum {$changed} barang diperbarui."
            : 'Batas minimum stok disimpan.');
    }

    #[Computed]
    public function rows(): array
    {
        return app(ProductListing::class)->query(['search' => $this->filter])
            ->get()
            ->map(fn (Product $p) => ProductCatalog::toArray($p))
            ->sortBy([['category', 'asc'], ['name', 'asc']])
            ->groupBy('category')
            ->all();
    }

    public function render()
    {
        return view('livewire.barang.min-stock-settings', [
            'categories' => app(ProductListing::class)->categories(),
        ]);
    }
}
