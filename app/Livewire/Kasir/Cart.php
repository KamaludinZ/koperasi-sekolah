<?php

namespace App\Livewire\Kasir;

use App\Services\CheckoutService;
use App\Models\Member;
use App\Services\CouponService;
use App\Services\MemberService;
use App\Services\ManualDiscountService;
use App\Services\PromoEngine;
use App\Services\ProductCatalog;
use App\Services\ReceiptPdfService;
use App\Services\ReceiptPresenter;
use App\Services\SettingsService;
use App\Support\MockData;
use App\Support\PriceCalculator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Keranjang belanja kasir. Menerima event `product-picked` dari
 * ProductPicker dan menghitung subtotal, diskon manual, pajak, pembulatan, dan total (PriceCalculator).
 * Isi keranjang: [productId => qty]; harga diambil ulang dari data barang.
 * Diskon manual (nominal/persen + alasan) dibatasi aturan diskon kasir
 * (pengaturan `discount`, lihat ManualDiscountService; admin boleh melewati batas) dan dipotong sebelum pajak.
 * Metode bayar: tunai, QRIS/transfer, saldo tabungan anggota, atau bon (bayar nanti) — dua terakhir
 * wajib memilih anggota; saldo dibaca dari data anggota, batas bon tahap ini dari MockData::memberCredit().
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

    public const PAYMENT_METHODS = CheckoutService::PAYMENT_LABELS;

    /** Metode yang memerlukan anggota. */
    public const MEMBER_METHODS = ['tabungan', 'bon'];

    /** Pilihan jatuh tempo bon (hari). */
    public const BON_TERMS = [7 => '1 minggu', 14 => '2 minggu', 30 => '1 bulan'];

    /** Anggota yang berbelanja (opsional; wajib untuk tabungan & bon). */
    public ?int $memberId = null;

    public string $memberSearch = '';

    public int $bonDays = 14;

    /** Jatuh tempo pilihan sendiri (dipakai bila $bonDays = 0). */
    public string $bonDueAt = '';

    /** Keterangan bon, mis. "dibayar orang tua". */
    public string $bonNote = '';

    /** Anggota berjanji melunasi bon sebelum jatuh tempo (wajib untuk metode bon). */
    public bool $bonConsent = false;

    /** Rentang jatuh tempo bon yang boleh dipilih (hari). */
    public const BON_MAX_DAYS = 60;

    /** Anggota menyetujui saldo tabungannya dipotong (wajib untuk metode tabungan). */
    public bool $savingsConsent = false;

    /** Saldo tabungan di bawah batas ini setelah bayar ditandai menipis. */
    public const LOW_BALANCE = 10_000;

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
        $this->clearMember();
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
        $result = app(CouponService::class)->check($this->couponInput, $this->couponBase());
        if (! $result['ok']) {
            $this->addError('couponInput', $result['message']);

            return;
        }
        $this->appliedCoupon = $result['coupon']['code'];
        $this->couponInput = '';
        $this->editingCoupon = false;
        unset($this->coupon, $this->summary);
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
        return $this->appliedCoupon ? app(CouponService::class)->check($this->appliedCoupon, $this->couponBase()) : null;
    }

    /** Dasar potongan kupon: subtotal setelah promo otomatis (sama dengan CheckoutService). */
    private function couponBase(): int
    {
        return array_sum(array_column($this->lines, 'subtotal')) - $this->promos['total'];
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
    public function applyDiscount(ManualDiscountService $discounts): void
    {
        $isPercent = $this->discountType === 'persen';

        $this->validate([
            'discountType' => ['required', 'in:'.implode(',', array_keys(ManualDiscountService::TYPES))],
            'discountValue' => ['required', 'integer', 'min:1', $isPercent ? 'max:100' : 'max:10000000'],
            'discountReason' => ['nullable', 'string', 'max:100'],
        ], [
            'discountValue.required' => 'Isi besar diskon.',
            'discountValue.min' => 'Diskon minimal 1.',
            'discountValue.max' => $isPercent ? 'Diskon maksimal 100%.' : 'Diskon terlalu besar.',
        ]);

        $spec = ['type' => $this->discountType, 'value' => (int) $this->discountValue, 'reason' => trim($this->discountReason)];
        try {
            $discounts->resolve($spec, $this->summary['subtotal'] - $this->summary['promo'] - $this->summary['coupon'], auth()->user());
        } catch (ValidationException $e) {
            $message = $e->errors()['discount'][0];
            $this->addError($message === 'Tulis alasan diskon.' ? 'discountReason' : 'discountValue', $message);

            return;
        }

        $this->discount = $spec;
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
        return $discount ? ManualDiscountService::amount($discount['type'], (int) $discount['value'], $subtotal) : 0;
    }

    public function openPayment(): void
    {
        if ($this->summary['count'] === 0) {
            return;
        }

        $this->resetValidation();
        $this->paymentMethod = 'tunai';
        $this->paid = null;
        $this->savingsConsent = false;
        $this->reset('bonDays', 'bonDueAt', 'bonNote', 'bonConsent');
        $this->paying = true;
    }

    public function closePayment(): void
    {
        $this->paying = false;
    }

    /** @return list<array<string, mixed>> hasil pencarian anggota aktif (maks. 6) */
    #[Computed]
    public function memberResults(): array
    {
        return mb_strlen(trim($this->memberSearch)) >= 2 ? app(MemberService::class)->search($this->memberSearch, 6) : [];
    }

    /** Anggota terpilih + saldo tabungan & status bon. */
    #[Computed]
    public function member(): ?array
    {
        $m = $this->memberId ? Member::active()->find($this->memberId) : null;

        return $m ? MemberService::toArray($m) + ['credit' => MockData::memberCredit($m->id, $m->type)] : null;
    }

    public function selectMember(int $id): void
    {
        $this->memberId = Member::active()->whereKey($id)->exists() ? $id : null;
        $this->memberSearch = '';
        $this->savingsConsent = false;
        $this->bonConsent = false;
        $this->resetValidation('memberId');
        unset($this->member, $this->memberResults);
    }

    public function clearMember(): void
    {
        $this->reset('memberId', 'memberSearch', 'savingsConsent', 'bonConsent');
        unset($this->member);
    }

    /** Tanggal jatuh tempo bon yang berlaku (Y-m-d), atau null bila tanggal sendiri belum diisi. */
    public function bonDueDate(): ?string
    {
        if ($this->bonDays > 0) {
            return now()->addDays($this->bonDays)->toDateString();
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->bonDueAt) ? $this->bonDueAt : null;
    }

    /** Saldo tidak cukup: pindah ke tunai tanpa kehilangan isi keranjang. */
    public function switchToCash(): void
    {
        $this->paymentMethod = 'tunai';
        $this->paid = null;
        $this->resetValidation();
    }

    /** Kekurangan bila saldo/batas bon tidak cukup (0 = cukup). */
    public static function shortfall(string $method, ?array $member, int $total): int
    {
        return match (true) {
            ! $member => 0,
            $method === 'tabungan' => max(0, $total - (int) $member['balance']),
            $method === 'bon' => max(0, $total - $member['credit']['available']),
            default => 0,
        };
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

        $this->resetErrorBag();
        if ($this->paymentMethod !== 'tunai') {
            $this->paid = $summary['total'];
        }

        if (in_array($this->paymentMethod, self::MEMBER_METHODS, true)) {
            $member = $this->member;
            if (! $member) {
                $this->addError('memberId', 'Pilih anggota yang membayar dengan '.mb_strtolower(self::PAYMENT_METHODS[$this->paymentMethod]).'.');

                return;
            }
            if ($short = self::shortfall($this->paymentMethod, $member, $summary['total'])) {
                $this->addError('memberId', ($this->paymentMethod === 'tabungan' ? 'Saldo tabungan kurang ' : 'Melebihi sisa batas bon sebesar ').'Rp'.number_format($short, 0, ',', '.').'.');

                return;
            }
            if ($this->paymentMethod === 'bon' && $member['credit']['overdue']) {
                $this->addError('memberId', 'Anggota masih punya bon yang lewat jatuh tempo. Minta dilunasi dulu.');

                return;
            }
            if ($this->paymentMethod === 'bon') {
                $this->validate([
                    'bonDays' => ['required', 'integer', 'in:0,'.implode(',', array_keys(self::BON_TERMS))],
                    'bonDueAt' => ['exclude_unless:bonDays,0', 'required', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.now()->addDays(self::BON_MAX_DAYS)->toDateString()],
                    'bonNote' => ['nullable', 'string', 'max:100'],
                    'bonConsent' => ['accepted'],
                ], [
                    'bonDueAt.required' => 'Pilih tanggal jatuh tempo bon.',
                    'bonDueAt.after' => 'Jatuh tempo paling cepat besok.',
                    'bonDueAt.before_or_equal' => 'Jatuh tempo paling lama '.self::BON_MAX_DAYS.' hari dari hari ini.',
                    'bonNote.max' => 'Keterangan maksimal 100 karakter.',
                    'bonConsent.accepted' => 'Pastikan anggota sudah menyetujui bon dan jatuh temponya.',
                ]);
            }
            if ($this->paymentMethod === 'tabungan' && ! $this->savingsConsent) {
                $this->addError('savingsConsent', 'Pastikan anggota menyetujui pemotongan saldo tabungannya.');

                return;
            }
            if ($this->paymentMethod === 'bon') {
                // Bon disimpan setelah service bon tersedia.
                $this->addError('paymentMethod', 'Pembayaran '.mb_strtolower(self::PAYMENT_METHODS[$this->paymentMethod]).' belum dapat disimpan (fitur sedang disiapkan).');

                return;
            }
        }

        $this->validate([
            'paymentMethod' => ['required', 'in:'.implode(',', array_keys(self::PAYMENT_METHODS))],
            'paid' => ['required', 'integer', 'min:'.$summary['total']],
        ], [
            'paid.required' => 'Masukkan jumlah uang yang dibayar.',
            'paid.min' => 'Uang bayar kurang dari total belanja.',
        ]);

        try {
            // Diskon dicek ulang di server (isi keranjang bisa berubah setelah diskon persen diterapkan).
            $memberId = in_array($this->paymentMethod, self::MEMBER_METHODS, true) ? $this->memberId : null;
            $transaction = $checkout->checkout($this->items, $this->paymentMethod, $this->paid, auth()->id(), $memberId, $this->discount, $this->appliedCoupon);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(match ($field) {
                    'payment_method' => 'paymentMethod',
                    'member_id' => 'memberId',
                    'discount', 'coupon' => 'paid', // tampil di panel bayar
                    default => $field,
                }, (['discount' => 'Diskon: ', 'coupon' => 'Kupon: '][$field] ?? '').implode(' ', $messages));
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
        $promo = $this->promos['total'];
        $coupon = ($c = $this->coupon) && $c['ok'] ? $c['amount'] : 0;
        // Urutan sama dengan CheckoutService: promo otomatis → kupon → diskon manual dari sisanya.
        $manual = self::discountAmount($this->discount, $subtotal - $promo - $coupon);
        $discount = $promo + $coupon + $manual;
        $net = $subtotal - $discount;
        $tax = app(SettingsService::class)->tax();
        $price = PriceCalculator::compute($net, $tax, $this->paymentMethod);

        return [
            'count' => array_sum(array_column($this->lines, 'quantity')),
            'subtotal' => $subtotal,
            'promo' => $promo,
            'coupon' => $coupon,
            'manual_discount' => $manual,
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

    /** Promo otomatis yang berlaku untuk isi keranjang (PromoEngine) — ikut memotong total. */
    #[Computed]
    public function promos(): array
    {
        return app(PromoEngine::class)->evaluate($this->lines);
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
