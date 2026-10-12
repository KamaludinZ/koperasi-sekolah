<?php

namespace App\Livewire\Kasir;

use App\Services\ProductCatalog;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Pencarian & pemilihan barang di layar kasir.
 * Memilih barang mengirim event `product-picked` (payload: productId)
 * yang akan ditangkap oleh komponen keranjang.
 */
class ProductPicker extends Component
{
    public string $search = '';

    public string $category = '';

    public ?int $lastPicked = null;

    public function setCategory(string $category): void
    {
        $this->category = $this->category === $category ? '' : $category;
    }

    #[Computed]
    public function products(): array
    {
        return app(ProductCatalog::class)->search($this->search, $this->category);
    }

    public function pick(int $productId): void
    {
        $product = app(ProductCatalog::class)->find($productId);

        if (! $product || $product['stock'] < 1) {
            return;
        }

        $this->lastPicked = $productId;
        $this->dispatch('product-picked', productId: $productId);
    }

    /**
     * Enter di kolom cari: kode yang cocok persis (scan barcode) atau
     * satu-satunya hasil langsung dipilih, lalu kolom cari dikosongkan.
     */
    public function pickFromSearch(): void
    {
        $term = mb_strtolower(trim($this->search));
        if ($term === '') {
            return;
        }

        $results = $this->products;
        $match = app(ProductCatalog::class)->findByCode($term)
            ?? (count($results) === 1 ? $results[0] : null);

        if ($match) {
            $this->pick($match['id']);
            $this->search = '';
            unset($this->products);
        }
    }

    public function render()
    {
        return view('livewire.kasir.product-picker', [
            'categories' => app(ProductCatalog::class)->categories(),
        ]);
    }
}
