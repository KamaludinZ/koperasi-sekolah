<?php

namespace App\Livewire\Kasir;

use App\Services\CheckoutService;
use App\Services\ProductCatalog;
use App\Services\ReceiptPdfService;
use App\Services\ReceiptPresenter;
use App\Services\SettingsService;
use App\Support\CouponValidator;
use App\Support\MockData;
use App\Support\PriceCalculator;
use App\Support\PromoMatcher;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Keranjang belanja kasir. Menerima event `product-picked` dari
 * ProductPicker dan menghitung subtotal, diskon manual, pajak, pembulatan, dan total (PriceCalculator).
 * Isi keranjang: [productId => qty]; harga diambil ulang dari data barang.
 * Diskon manual (nominal/persen + alasan) dibatasi aturan diskon kasir
 * (tahap ini dari MockData::manualDiscountRules) dan dipotong sebelum pajak.
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

    /** Diskon manual yang diterapkan: ['type' => 'nominal'|'persen', 'value' => int, 'reason' => string]. */
    public ?array $discount = null;

    /** Isian form diskon (sebelum diterapkan). */
    public bool $editingDiscount = false;

    public string $discountType = 'persen';

    public ?int $discountValue = null;

    public string $discountReason = '';

    /** Kode kupon yang sudah diterima (divalidasi ulang tiap keranjang berubah). */
    public ?string $appliedCoupon = null;

    public bool $editingCoupon = false;

    public string $couponInput = '';

    public const DISCOUNT_REASONS = ['Barang cacat/kemasan rusak', 'Pelanggan tetap', 'Kegiatan sekolah', 'Penyesuaian harga'];

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
        $this->removeDiscount();
        $this->removeCoupon();
    }

    public function editCoupon(): void
    {
        $this->resetValidation('couponInput');
        $this->couponInput = $this->appliedCoupon ?? '';
        $this->editingCoupon = true;
    }

    public function cancelCoupon(): void
    {
        $this->editingCoupon = false;
        $this->resetValidation('couponInput');
    }

    /** Periksa kode kupon dan beri umpan balik; kode yang valid disimpan di keranjang. */
    public function applyCoupon(): void
    {
        $this->resetValidation('couponInput');
        $result = CouponValidator::check($this->couponInput, $this->summary['subtotal'], MockData::coupons());
        if (! $result['ok']) {
            $this->addError('couponInput', $result['message']);

            return;
        }
        $this->appliedCoupon = $result['coupon']['code'];
        $this->couponInput = '';
        $this->editingCoupon = false;
        unset($this->coupon);
    }

    public function removeCoupon(): void
    {
        $this->appliedCoupon = null;
        $this->couponInput = '';
        $this->editingCoupon = false;
        unset($this->coupon);
    }

    /**
     * Hasil pemeriksaan kupon yang diterapkan terhadap isi keranjang saat ini (bisa jadi tidak
     * memenuhi lagi, mis. minimal belanja). Tahap frontend: pratinjau, belum memotong total.
     */
    #[Computed]
    public function coupon(): ?array
    {
        return $this->appliedCoupon ? CouponValidator::check($this->appliedCoupon, $this->summary['subtotal'], MockData::coupons()) : null;
    }

    public function editDiscount(): void
    {
        if ($this->summary['count'] === 0) {
            return;
        }
        $this->resetValidation(['discountValue', 'discountReason']);
        $this->discountType = $this->discount['type'] ?? 'persen';
        $this->discountValue = $this->discount['value'] ?? null;
        $this->discountReason = $this->discount['reason'] ?? '';
        $this->editingDiscount = true;
    }

    public function cancelDiscount(): void
    {
        $this->editingDiscount = false;
        $this->resetValidation(['discountValue', 'discountReason']);
    }

    /** Terapkan diskon manual setelah dicek terhadap batas diskon kasir. */
    public function applyDiscount(): void
    {
        $rules = MockData::manualDiscountRules();
        $isPercent = $this->discountType === 'persen';

        $this->validate([
            'discountType' => ['required', 'in:nominal,persen'],
            'discountValue' => ['required', 'integer', 'min:1', $isPercent ? 'max:100' : 'max:10000000'],
            'discountReason' => [$rules['reason_required'] ? 'required' : 'nullable', 'string', 'max:100'],
        ], [
            'discountValue.required' => 'Isi besar diskon.',
            'discountValue.min' => 'Diskon minimal 1.',
            'discountValue.max' => $isPercent ? 'Diskon maksimal 100%.' : 'Diskon terlalu besar.',
            'discountReason.required' => 'Tulis alasan diskon.',
        ]);

        if (! $rules['enabled']) {
            $this->addError('discountValue', 'Diskon manual sedang dimatikan oleh admin.');

            return;
        }
        $amount = self::discountAmount(['type' => $this->discountType, 'value' => $this->discountValue], $this->summary['subtotal']);
        if (($isPercent && $this->discountValue > $rules['max_percent']) || $amount > $rules['max_amount']) {
            $this->addError('discountValue', 'Melebihi batas diskon kasir ('.$rules['max_percent'].'% · maks. Rp'.number_format($rules['max_amount'], 0, ',', '.').'). Minta persetujuan admin.');

            return;
        }
        if (! $isPercent && $this->discountValue > $this->summary['subtotal']) {
            $this->addError('discountValue', 'Diskon tidak boleh melebihi subtotal belanja.');

            return;
        }

        $this->discount = ['type' => $this->discountType, 'value' => $this->discountValue, 'reason' => trim($this->discountReason)];
        $this->editingDiscount = false;
        unset($this->summary);
    }

    public function removeDiscount(): void
    {
        $this->discount = null;
        $this->editingDiscount = false;
        $this->discountValue = null;
        $this->discountReason = '';
        unset($this->summary);
    }

    /** Rupiah potongan dari diskon (persen dibulatkan ke rupiah), tidak melebihi subtotal. */
    public static function discountAmount(?array $discount, int $subtotal): int
    {
        if (! $discount || $subtotal <= 0) {
            return 0;
        }
        $amount = $discount['type'] === 'persen' ? (int) round($subtotal * $discount['value'] / 100) : (int) $discount['value'];

        return max(0, min($amount, $subtotal));
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

        // Isi keranjang bisa berubah setelah diskon persen diterapkan: cek ulang batasnya.
        if ($summary['discount'] > MockData::manualDiscountRules()['max_amount']) {
            $this->addError('paid', 'Diskon melebihi batas kasir setelah keranjang berubah. Ubah diskonnya dulu.');

            return;
        }

        $this->validate([
            'paymentMethod' => ['required', 'in:'.implode(',', array_keys(self::PAYMENT_METHODS))],
            'paid' => ['required', 'integer', 'min:'.$summary['total']],
        ], [
            'paid.required' => 'Masukkan jumlah uang yang dibayar.',
            'paid.min' => 'Uang bayar kurang dari total belanja.',
        ]);

        try {
            $transaction = $checkout->checkout($this->items, $this->paymentMethod, $this->paid, auth()->id(), null, $summary['discount'], $this->discount['reason'] ?? null);
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
        $discount = self::discountAmount($this->discount, $subtotal);
        $net = $subtotal - $discount;
        $tax = app(SettingsService::class)->tax();
        $price = PriceCalculator::compute($net, $tax, $this->paymentMethod);

        return [
            'count' => array_sum(array_column($this->lines, 'quantity')),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_enabled' => (bool) $tax['enabled'],
            'tax_label' => $tax['label'],
            'tax_rate' => $tax['enabled'] ? (float) $tax['rate'] : 0.0,
            'tax' => $price['tax'],
            'tax_included' => $price['tax_included'],
            'rounding' => $price['rounding'],
            'total' => $price['payable'], // yang dibayar pembeli (setelah pembulatan)
            // Total & pembulatan per metode bayar (pembulatan bisa khusus tunai) untuk panel bayar.
            'totals' => collect(self::PAYMENT_METHODS)->map(fn ($label, $method) => $method === $this->paymentMethod
                ? ['payable' => $price['payable'], 'rounding' => $price['rounding']]
                : ['payable' => ($p = PriceCalculator::compute($net, $tax, $method))['payable'], 'rounding' => $p['rounding']])->all(),
        ];
    }

    /**
     * Promo otomatis yang cocok dengan isi keranjang (PromoMatcher). Tahap frontend: pratinjau
     * dari data tiruan — belum memotong total yang dibayar.
     */
    #[Computed]
    public function promos(): array
    {
        return PromoMatcher::match($this->lines, MockData::promos());
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
