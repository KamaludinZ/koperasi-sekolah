<?php

namespace App\Livewire\Kasir;

use App\Support\MockData;
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
        $term = mb_strtolower(trim($this->search));

        return array_values(array_filter(MockData::activeProducts(), function (array $p) use ($term) {
            if ($this->category !== '' && $p['category'] !== $this->category) {
                return false;
            }

            return $term === ''
                || str_contains(mb_strtolower($p['name']), $term)
                || str_contains(mb_strtolower($p['code']), $term);
        }));
    }

    public function pick(int $productId): void
    {
        $product = MockData::findProduct($productId);

        if (! $product || ! $product['is_active'] || $product['stock'] < 1) {
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
        $match = collect($results)->first(fn ($p) => mb_strtolower($p['code']) === $term)
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
            'categories' => MockData::categories(),
        ]);
    }
}
