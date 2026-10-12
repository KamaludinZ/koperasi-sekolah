<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use App\Support\MockData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan transaksi penjualan kasir secara atomik:
 * kunci baris barang → validasi stok → hitung total di server →
 * simpan transaksi + item (snapshot nama & harga) → kurangi stok → catat mutasi stok.
 */
class CheckoutService
{
    public function __construct(private StockService $stock) {}

    public const PAYMENT_METHODS = ['tunai' => 'Tunai', 'qris' => 'QRIS / Transfer'];

    /**
     * @param  array<int, int>  $items  [productId => quantity]
     * @param  int|null  $paid  uang dibayar; untuk non-tunai otomatis sama dengan total
     *
     * @throws ValidationException bila keranjang kosong, stok kurang, atau bayar kurang
     */
    public function checkout(array $items, string $paymentMethod, ?int $paid, ?int $userId = null, ?int $memberId = null): Transaction
    {
        $items = array_filter(array_map('intval', $items), fn ($qty) => $qty > 0);

        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Keranjang masih kosong.']);
        }
        if (! array_key_exists($paymentMethod, self::PAYMENT_METHODS)) {
            throw ValidationException::withMessages(['payment_method' => 'Metode bayar tidak dikenal.']);
        }

        return DB::transaction(function () use ($items, $paymentMethod, $paid, $userId, $memberId) {
            // Kunci baris barang (urut id agar tidak deadlock) supaya stok tidak terjual ganda.
            $products = Product::active()
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
            $tax = (int) round($subtotal * MockData::taxRate() / 100);
            $total = $subtotal + $tax;
            $paid = $paymentMethod === 'tunai' ? $paid : $total;

            if ($paid === null || $paid < $total) {
                throw ValidationException::withMessages(['paid' => 'Uang bayar kurang dari total belanja.']);
            }

            $transaction = $this->createWithInvoiceNumber([
                'user_id' => $userId,
                'member_id' => $memberId,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => 0,
                'total' => $total,
                'paid' => $paid,
                'change' => $paid - $total,
                'payment_method' => $paymentMethod,
            ]);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];

                $transaction->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'price' => $line['price'],
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                ]);

                $this->stock->decrease($product, $line['quantity'], "Penjualan {$transaction->invoice_number}", $userId);
            }

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
