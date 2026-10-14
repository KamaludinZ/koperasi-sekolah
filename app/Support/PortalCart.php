<?php

namespace App\Support;

use App\Services\ProductCatalog;
use Illuminate\Support\Facades\Auth;

/**
 * Keranjang belanja portal anggota, disimpan di sesi per anggota: [productId => qty].
 * Harga & stok selalu dibaca ulang dari data barang (ProductCatalog); jumlah dibatasi stok tersedia.
 */
class PortalCart
{
    public const MAX_LINES = 30;

    private static function key(): string
    {
        return 'portal.cart.'.Auth::guard('member')->id();
    }

    /** @return array<int, int> */
    public static function items(): array
    {
        return array_filter(array_map('intval', (array) session(self::key(), [])), fn ($q) => $q > 0);
    }

    /** Ubah jumlah (0 = hapus). Mengembalikan jumlah akhir setelah dibatasi stok. */
    public static function set(int $productId, int $qty): int
    {
        $items = self::items();
        $product = app(ProductCatalog::class)->find($productId);
        $qty = $product ? max(0, min($qty, (int) $product['stock'])) : 0;
        if ($qty === 0) {
            unset($items[$productId]);
        } elseif (isset($items[$productId]) || count($items) < self::MAX_LINES) {
            $items[$productId] = $qty;
        }
        session([self::key() => $items]);

        return $items[$productId] ?? 0;
    }

    public static function clear(): void
    {
        session()->forget(self::key());
    }

    /**
     * Baris keranjang dengan harga & stok terkini; barang yang tidak lagi dijual dibuang.
     *
     * @return array{lines: list<array{id: int, code: string, name: string, unit: string, price: int, qty: int, stock: int, subtotal: int}>, count: int, total: int}
     */
    public static function summary(): array
    {
        $items = self::items();
        $products = app(ProductCatalog::class)->findMany(array_keys($items));
        $lines = [];
        foreach ($items as $id => $qty) {
            if (! $p = $products[$id] ?? null) {
                continue;
            }
            $qty = min($qty, (int) $p['stock']);
            if ($qty < 1) {
                continue;
            }
            $lines[] = ['id' => $id, 'code' => $p['code'], 'name' => $p['name'], 'unit' => $p['unit'], 'price' => (int) $p['price'],
                'qty' => $qty, 'stock' => (int) $p['stock'], 'subtotal' => $qty * (int) $p['price']];
        }

        return ['lines' => $lines, 'count' => array_sum(array_column($lines, 'qty')), 'total' => array_sum(array_column($lines, 'subtotal'))];
    }
}
