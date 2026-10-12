<?php

namespace App\Livewire\Kasir;

use App\Services\CheckoutService;
use App\Services\ProductCatalog;
use App\Services\ReceiptPdfService;
use App\Services\ReceiptPresenter;
use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Validation\ValidationException;
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

    /** Item terakhir yang dihapus, agar bisa diurungkan: ['id' => int, 'qty' => int, 'name' => string]. */
    public ?array $lastRemoved = null;

    public bool $paying = false;

    public string $paymentMethod = 'tunai';

    public ?int $paid = null;

    /** Ringkasan transaksi terakhir yang berhasil dibayar (untuk dialog sukses & struk). */
    public ?array $lastTransaction = null;

    public const PAYMENT_METHODS = CheckoutService::PAYMENT_METHODS;

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
        $this->lastRemoved = null;
        $product = $this->catalog()[$productId] ?? app(ProductCatalog::class)->find($productId);

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
        if (! isset($this->items[$productId])) {
            return;
        }

        $this->lastRemoved = [
            'id' => $productId,
            'qty' => $this->items[$productId],
            'name' => $this->catalog()[$productId]['name'] ?? '',
        ];
        unset($this->items[$productId]);
    }

    public function undoRemove(): void
    {
        if ($this->lastRemoved) {
            $this->setQty($this->lastRemoved['id'], ($this->items[$this->lastRemoved['id']] ?? 0) + $this->lastRemoved['qty']);
            $this->lastRemoved = null;
        }
    }

    public function clear(): void
    {
        $this->items = [];
        $this->notice = null;
        $this->lastRemoved = null;
    }

    public function openPayment(): void
    {
        if ($this->summary['count'] === 0) {
            return;
        }

        $this->resetValidation();
        $this->paymentMethod = 'tunai';
        $this->paid = null;
        $this->paying = true;
    }

    public function closePayment(): void
    {
        $this->paying = false;
    }

    /**
     * Konfirmasi pembayaran: simpan transaksi ke database & kurangi stok
     * lewat CheckoutService (total dihitung ulang di server).
     */
    public function pay(CheckoutService $checkout): void
    {
        $summary = $this->summary;
        if ($summary['count'] === 0) {
            return;
        }

        if ($this->paymentMethod !== 'tunai') {
            $this->paid = $summary['total'];
        }

        $this->validate([
            'paymentMethod' => ['required', 'in:'.implode(',', array_keys(self::PAYMENT_METHODS))],
            'paid' => ['required', 'integer', 'min:'.$summary['total']],
        ], [
            'paid.required' => 'Masukkan jumlah uang yang dibayar.',
            'paid.min' => 'Uang bayar kurang dari total belanja.',
        ]);

        try {
            $transaction = $checkout->checkout($this->items, $this->paymentMethod, $this->paid, auth()->id());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'payment_method' ? 'paymentMethod' : $field, implode(' ', $messages));
            }

            return;
        }

        $this->lastTransaction = app(ReceiptPresenter::class)->present($transaction);

        try {
            app(ReceiptPdfService::class)->store($transaction);
        } catch (\Throwable $e) {
            report($e); // struk PDF bisa dibuat ulang dari halaman struk
        }

        $this->dispatch('transaction-completed', invoice: $transaction->invoice_number);
        $this->paying = false;
        $this->clear();
    }

    public function newTransaction(): void
    {
        $this->lastTransaction = null;
        $this->paying = false;
        $this->paid = null;
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
        return app(ProductCatalog::class)->findMany(array_keys($this->items));
    }

    public function render()
    {
        return view('livewire.kasir.cart');
    }
}
