<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\NotificationService;

/**
 * Jaring pengaman riwayat stok: bila stok barang berubah lewat model
 * (mis. Product::update, impor, tinker) dan bukan lewat StockService,
 * perubahan tetap dicatat otomatis sebagai mutasi stok.
 *
 * StockService memperbarui stok lewat query builder (tanpa event model),
 * jadi perubahan dari sana tidak tercatat dua kali.
 *
 * Juga mengantre pemeriksaan peringatan stok (NotificationService) saat barang dibuat,
 * stok/batas minimum/status aktif berubah, atau barang dihapus.
 */
class ProductObserver
{
    public function created(Product $product): void
    {
        if ($product->stock > 0) {
            $this->record($product, StockMovement::TYPE_IN, 0, $product->stock, 'Stok awal');
        }
        app(NotificationService::class)->queueStockCheck($product->id);
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged(['stock', 'min_stock', 'is_active', 'name'])) {
            app(NotificationService::class)->queueStockCheck($product->id);
        }
        if (! $product->wasChanged('stock')) {
            return;
        }

        $before = (int) $product->getOriginal('stock');
        $after = (int) $product->stock;
        $type = $after > $before ? StockMovement::TYPE_IN : StockMovement::TYPE_OUT;

        $this->record($product, $type, $before, $after, 'Perubahan stok tercatat otomatis');
    }

    /** Peringatan stok barang yang dihapus ikut dihapus (FK hanya mengosongkan product_id). */
    public function deleting(Product $product): void
    {
        Notification::where('type', 'stok')->where('product_id', $product->id)->delete();
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
