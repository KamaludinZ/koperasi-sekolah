<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PriceCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan transaksi penjualan kasir secara atomik:
 * kunci baris barang → validasi stok → hitung total di server (promo otomatis lewat PromoEngine,
 * kupon lewat CouponService (baris kupon dikunci untuk kuota), lalu diskon manual yang dicek batasnya lewat ManualDiscountService, lalu pajak & pembulatan dari
 * pengaturan, lihat PriceCalculator) → simpan rincian diskon (transaction_discounts) →
 * simpan transaksi + item (snapshot nama & harga), tertaut ke shift kasir yang terbuka →
 * kurangi stok → catat mutasi stok.
 */
class CheckoutService
{
    public function __construct(
        private StockService $stock,
        private SettingsService $settings,
        private ManualDiscountService $manualDiscounts,
        private PromoEngine $promos,
        private CouponService $coupons,
        private SavingsPaymentService $savings,
        private MemberCreditService $credits,
    ) {}

    public const PAYMENT_METHODS = ['tunai' => 'Tunai', 'qris' => 'QRIS / Transfer'];

    /** Metode bayar atas nama anggota (saldo tabungan / bon). */
    public const MEMBER_PAYMENT_METHODS = ['tabungan' => 'Saldo tabungan', 'bon' => 'Bon (bayar nanti)'];

    /** Label semua metode bayar untuk struk, riwayat, dan lencana. */
    public const PAYMENT_LABELS = self::PAYMENT_METHODS + self::MEMBER_PAYMENT_METHODS;

    /** Metode yang bisa disimpan lewat checkout. */
    public const CHECKOUT_METHODS = ['tunai', 'qris', 'tabungan', 'bon'];

    /**
     * @param  array<int, int>  $items  [productId => quantity]
     * @param  int|null  $paid  uang dibayar; untuk non-tunai otomatis sama dengan total
     * @param  array{type: string, value: int, reason?: ?string}|null  $manualDiscount  diskon manual kasir (persen/rupiah)
     *                                                                                   atas subtotal, sebelum pajak — dicek batasnya
     * @param  string|null  $couponCode  kode kupon/voucher (dipotong setelah promo, sebelum diskon manual)
     * @param  array{due_at?: ?string, note?: ?string}|null  $credit  jatuh tempo & keterangan untuk metode bon
     *
     * @throws ValidationException bila keranjang kosong, stok kurang, atau bayar kurang
     */
    public function checkout(array $items, string $paymentMethod, ?int $paid, ?int $userId = null, ?int $memberId = null, ?array $manualDiscount = null, ?string $couponCode = null, ?array $credit = null): Transaction
    {
        $items = array_filter(array_map('intval', $items), fn ($qty) => $qty > 0);

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Keranjang masih kosong.']);
        }
        if (! in_array($paymentMethod, self::CHECKOUT_METHODS, true)) {
            throw ValidationException::withMessages(['payment_method' => 'Metode bayar tidak dikenal.']);
        }
        if (array_key_exists($paymentMethod, self::MEMBER_PAYMENT_METHODS) && ! $memberId) {
            throw ValidationException::withMessages(['member_id' => 'Pilih anggota yang membayar dengan '.mb_strtolower(self::PAYMENT_LABELS[$paymentMethod]).'.']);
        }

        if ($paymentMethod === 'bon') {
            MemberCreditService::validDueDate($credit['due_at'] ?? null); // gagal cepat sebelum mengunci stok
        }

        return DB::transaction(function () use ($items, $paymentMethod, $paid, $userId, $memberId, $manualDiscount, $couponCode, $credit) {
            // Kunci baris barang (urut id agar tidak deadlock) supaya stok tidak terjual ganda.
            $products = Product::active()
                ->with('category:id,name')
                ->whereKey(array_keys($items))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lines = [];
            $errors = [];
            foreach ($items as $productId => $qty) {
                $product = $products->get($productId);
                if (! $product) {
                    $errors[] = 'Ada barang yang sudah tidak tersedia. Muat ulang keranjang.';

                    continue;
                }
                if ($product->stock < $qty) {
                    $errors[] = "Stok {$product->name} tinggal {$product->stock} {$product->unit}.";

                    continue;
                }

                $price = (int) round((float) $product->price);
                $lines[] = ['product' => $product, 'quantity' => $qty, 'price' => $price, 'subtotal' => $price * $qty];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['items' => array_values(array_unique($errors))]);
            }

            $subtotal = array_sum(array_column($lines, 'subtotal'));
            $taxSettings = $this->settings->tax();
            // Promo otomatis dulu (per barang), lalu diskon manual dari sisa belanja.
            $promo = $this->promos->evaluate(array_map(fn ($l) => [
                'id' => $l['product']->id, 'name' => $l['product']->name, 'category' => $l['product']->category?->name,
                'price' => $l['price'], 'quantity' => $l['quantity'], 'subtotal' => $l['subtotal'],
            ], $lines));
            $coupon = $this->coupons->redeem($couponCode, $subtotal - $promo['total'], $memberId);
            $afterCoupon = $subtotal - $promo['total'] - ($coupon['amount'] ?? 0);
            $manual = $this->manualDiscounts->resolve($manualDiscount, $afterCoupon, $userId ? User::find($userId) : null);
            $discount = $promo['total'] + ($coupon['amount'] ?? 0) + ($manual['amount'] ?? 0);
            $discountReason = $manual['reason'] ?? null;
            $price = PriceCalculator::compute($subtotal - $discount, $taxSettings, $paymentMethod);
            $total = $price['payable'];
            $paid = $paymentMethod === 'tunai' ? $paid : $total;

            if ($paid === null || $paid < $total) {
                throw ValidationException::withMessages(['paid' => 'Uang bayar kurang dari total belanja.']);
            }

            $transaction = $this->createWithInvoiceNumber([
                'user_id' => $userId,
                'cash_shift_id' => CashShift::open()->value('id'), // penjualan masuk ke laci shift berjalan
                'member_id' => $memberId,
                'subtotal' => $subtotal,
                'tax' => $price['tax'],
                'tax_label' => $taxSettings['enabled'] ? $taxSettings['label'] : null,
                'tax_rate' => $taxSettings['enabled'] ? (float) $taxSettings['rate'] : 0,
                'tax_included' => $price['tax_included'],
                'discount' => $discount,
                'rounding' => $price['rounding'],
                'total' => $total,
                'paid' => $paid,
                'change' => $paid - $total,
                'payment_method' => $paymentMethod,
                'item_count' => array_sum(array_column($lines, 'quantity')),
            ]);

            if ($paymentMethod === 'tabungan') {
                // Saldo dipotong di transaksi DB yang sama: saldo kurang → seluruh penjualan dibatalkan.
                $this->savings->charge($memberId, $total, $transaction, $userId);
            }
            if ($paymentMethod === 'bon') {
                // Bon dicek batasnya & dicatat di transaksi DB yang sama.
                $this->credits->record($memberId, $total, $transaction, $credit['due_at'] ?? null, $credit['note'] ?? null, $userId);
            }

            foreach ($promo['applied'] as $applied) {
                $transaction->discounts()->create([
                    'source' => 'promo', 'promo_id' => $applied['id'], 'label' => $applied['name'], 'amount' => $applied['saving'],
                    'reason' => implode(', ', $applied['items']),
                ]);
            }
            if ($coupon && $coupon['amount'] > 0) {
                $transaction->discounts()->create([
                    'source' => 'kupon', 'coupon_id' => $coupon['coupon_id'], 'label' => 'Kupon '.$coupon['code'], 'amount' => $coupon['amount'],
                ]);
            }
            if ($manual && $manual['amount'] > 0) {
                $transaction->discounts()->create([
                    'source' => 'manual', 'label' => $manual['label'], 'amount' => $manual['amount'], 'reason' => $manual['reason'],
                ]);
            }

            // Jaga konsistensi: rincian potongan harus berjumlah sama dengan kolom discount.
            if ((int) round((float) $transaction->discounts()->sum('amount')) !== $discount) {
                throw new \LogicException("Rincian diskon transaksi {$transaction->invoice_number} tidak sama dengan total diskon.");
            }

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];

                $transaction->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $line['price'],
                    'cost_price' => $product->cost_price, // snapshot untuk laba kotor
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                ]);

                $this->stock->decrease($product, $line['quantity'], "Penjualan {$transaction->invoice_number}", $userId);
            }

            AuditLog::record('sale.created', 'transaksi',
                "Penjualan {$transaction->invoice_number}: ".count($lines).' jenis barang, '.self::PAYMENT_LABELS[$paymentMethod],
                ['type' => 'transaction', 'id' => $transaction->id, 'label' => $transaction->invoice_number],
                [
                    'items' => array_map(fn ($l) => ['name' => $l['product']->name, 'qty' => $l['quantity'], 'subtotal' => $l['subtotal']], $lines),
                    'payment_method' => $paymentMethod,
                    'paid' => $paid,
                    'change' => $paid - $total,
                    'member_id' => $memberId,
                    'subtotal' => $subtotal,
                    'discount' => $discount ?: null,
                    'discount_reason' => $discountReason,
                    'coupon' => $coupon ? $coupon['code'].' −'.$coupon['amount'] : null,
                    'promos' => $promo['applied'] ? array_map(fn ($a) => $a['name'].' −'.$a['saving'], $promo['applied']) : null,
                    'tax' => $price['tax'] ?: null,
                    'tax_included' => $price['tax_included'] ?: null,
                    'rounding' => $price['rounding'] ?: null,
                ],
                $total,
            );

            return $transaction->load('items');
        });
    }

    /**
     * Nomor invoice berurutan per hari: INV-YYYYMMDD-0001.
     * Bila bentrok (dua kasir bersamaan), coba nomor berikutnya.
     */
    private function createWithInvoiceNumber(array $attributes): Transaction
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = Transaction::where('invoice_number', 'like', $prefix.'%')->max('invoice_number');
            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 + $attempt : 1 + $attempt;

            try {
                return DB::transaction(fn () => Transaction::create([
                    ...$attributes,
                    'invoice_number' => $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT),
                ]));
            } catch (UniqueConstraintViolationException) {
                // nomor sudah dipakai transaksi lain — ulangi
            }
        }

        throw new \RuntimeException('Gagal membuat nomor invoice unik.');
    }
}
