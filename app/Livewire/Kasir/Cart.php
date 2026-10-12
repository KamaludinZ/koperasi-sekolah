<?php

namespace App\Livewire\Kasir;

use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Keranjang belanja kasir. Menerima event `product-picked` dari
 * ProductPicker dan menghitung subtotal, pajak, dan total secara otomatis.
 * Isi keranjang: [productId => qty]; harga diambil ulang dari data barang.
 */
class Cart extends Component
{
    /** @var array<int, int> */
    public array $items = [];

    public ?string $notice = null;

    #[On('product-picked')]
    public function add(int $productId): void
    {
        $this->setQty($productId, ($this->items[$productId] ?? 0) + 1);
    }

    public function increment(int $productId): void
    {
        $this->setQty($productId, ($this->items[$productId] ?? 0) + 1);
    }

    public function decrement(int $productId): void
    {
        $this->setQty($productId, ($this->items[$productId] ?? 0) - 1);
    }

    public function setQty(int $productId, int $qty): void
    {
        $this->notice = null;
        $product = $this->catalog()[$productId] ?? null;

        if (! $product || $qty < 1) {
            unset($this->items[$productId]);

            return;
        }

        if ($qty > $product['stock']) {
            $qty = $product['stock'];
            $this->notice = "Stok {$product['name']} hanya tersisa {$product['stock']} {$product['unit']}.";
        }

        $this->items[$productId] = $qty;
    }

    public function remove(int $productId): void
    {
        unset($this->items[$productId]);
    }

    public function clear(): void
    {
        $this->items = [];
        $this->notice = null;
    }

    #[Computed]
    public function lines(): array
    {
        $catalog = $this->catalog();

        return collect($this->items)
            ->filter(fn ($qty, $id) => isset($catalog[$id]))
            ->map(fn ($qty, $id) => [
                ...$catalog[$id],
                'quantity' => $qty,
                'subtotal' => $catalog[$id]['price'] * $qty,
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function summary(): array
    {
        $subtotal = array_sum(array_column($this->lines, 'subtotal'));
        $taxRate = MockData::taxRate();
        $tax = (int) round($subtotal * $taxRate / 100);

        return [
            'count' => array_sum(array_column($this->lines, 'quantity')),
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ];
    }

    private function catalog(): array
    {
        return collect(MockData::products())->keyBy('id')->all();
    }

    public function render()
    {
        return view('livewire.kasir.cart');
    }
}
