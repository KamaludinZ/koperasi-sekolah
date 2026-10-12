<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\StockMovement;

/**
 * Jaring pengaman riwayat stok: bila stok barang berubah lewat model
 * (mis. Product::update, impor, tinker) dan bukan lewat StockService,
 * perubahan tetap dicatat otomatis sebagai mutasi stok.
 *
 * StockService memperbarui stok lewat query builder (tanpa event model),
 * jadi perubahan dari sana tidak tercatat dua kali.
 */
class ProductObserver
{
    public function created(Product $product): void
    {
        if ($product->stock > 0) {
            $this->record($product, StockMovement::TYPE_IN, 0, $product->stock, 'Stok awal');
        }
    }

    public function updated(Product $product): void
    {
        if (! $product->wasChanged('stock')) {
            return;
        }

        $before = (int) $product->getOriginal('stock');
        $after = (int) $product->stock;
        $type = $after > $before ? StockMovement::TYPE_IN : StockMovement::TYPE_OUT;

        $this->record($product, $type, $before, $after, 'Perubahan stok tercatat otomatis');
    }

    private function record(Product $product, string $type, int $before, int $after, string $note): void
    {
        StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => abs($after - $before),
            'stock_before' => $before,
            'stock_after' => $after,
            'note' => $note,
            'user_id' => auth()->id(),
        ]);
    }
}
