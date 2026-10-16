<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Satu pintu untuk mengubah stok barang. Setiap perubahan mengunci baris barang,
 * memperbarui kolom stock, dan mencatat StockMovement (stok sebelum/sesudah).
 */
class StockService
{
    /** Tambah stok (barang datang / restok). */
    public function increase(Product $product, int $quantity, ?string $note = null, ?int $userId = null): StockMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($product, StockMovement::TYPE_IN, $quantity, fn (int $stock) => $stock + $quantity, $note, $userId);
    }

    /**
     * Kurangi stok (penjualan, rusak, hilang).
     *
     * @throws ValidationException bila stok tidak cukup
     */
    /**
     * Kurangi stok sebanyak yang tersedia (paling sedikit 0) — untuk penjualan yang SUDAH terjadi di luar sistem
     * (kasir offline) sehingga tidak boleh ditolak. Mengembalikan pergerakan stok (null bila stok sudah 0) dan
     * jumlah yang kurang untuk dicocokkan lewat stok opname.
     *
     * @return array{movement: ?StockMovement, shortage: int}
     */
    public function decreaseAvailable(Product $product, int $quantity, ?string $note = null, ?int $userId = null): array
    {
        $this->assertPositive($quantity);
        $before = (int) Product::whereKey($product->id)->lockForUpdate()->value('stock');
        $take = min($before, $quantity);
        if ($take === 0) {
            return ['movement' => null, 'shortage' => $quantity];
        }

        return ['movement' => $this->decrease($product, $take, $note.($take < $quantity ? ' (kurang '.($quantity - $take).')' : ''), $userId), 'shortage' => $quantity - $take];
    }

    public function decrease(Product $product, int $quantity, ?string $note = null, ?int $userId = null): StockMovement
    {
        $this->assertPositive($quantity);

        return $this->apply($product, StockMovement::TYPE_OUT, $quantity, function (int $stock) use ($product, $quantity) {
            if ($stock < $quantity) {
                throw ValidationException::withMessages([
                    'stock' => "Stok {$product->name} tinggal {$stock} {$product->unit}.",
                ]);
            }

            return $stock - $quantity;
        }, $note, $userId);
    }

    /** Setel stok ke jumlah hasil hitung fisik (stock opname). Tidak mencatat apa pun bila sama. */
    public function adjustTo(Product $product, int $newStock, ?string $note = null, ?int $userId = null): ?StockMovement
    {
        if ($newStock < 0) {
            throw new InvalidArgumentException('Stok tidak boleh negatif.');
        }

        return DB::transaction(function () use ($product, $newStock, $note, $userId) {
            $current = Product::whereKey($product->id)->lockForUpdate()->value('stock');
            if ($current === $newStock) {
                return null;
            }

            return $this->apply($product, StockMovement::TYPE_ADJUSTMENT, abs($newStock - $current), fn () => $newStock, $note, $userId);
        });
    }

    /**
     * @param  callable(int): int  $compute  menghitung stok baru dari stok terkunci saat ini
     */
    private function apply(Product $product, string $type, int $quantity, callable $compute, ?string $note, ?int $userId): StockMovement
    {
        return DB::transaction(function () use ($product, $type, $quantity, $compute, $note, $userId) {
            $before = (int) Product::whereKey($product->id)->lockForUpdate()->value('stock');
            $after = $compute($before);

            Product::whereKey($product->id)->update(['stock' => $after]);
            $product->setAttribute('stock', $after)->syncOriginalAttribute('stock');
            app(NotificationService::class)->queueStockCheck($product->id);

            return StockMovement::create([
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $quantity,
                'stock_before' => $before,
                'stock_after' => $after,
                'note' => $note,
                'user_id' => $userId,
            ]);
        });
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Jumlah harus lebih dari 0.');
        }
    }
}
