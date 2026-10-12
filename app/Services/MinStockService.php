<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Batas minimum stok: nilai per barang (products.min_stock) dan nilai bawaan
 * untuk barang baru (settings: stock.default_min_stock).
 */
class MinStockService
{
    public const FALLBACK_DEFAULT = 5;

    public function defaultMinStock(): int
    {
        return (int) Setting::get(Setting::DEFAULT_MIN_STOCK, self::FALLBACK_DEFAULT);
    }

    public function setDefaultMinStock(int $value): void
    {
        $old = $this->defaultMinStock();
        if ($old !== $value) {
            AuditLog::record('setting.updated', 'stok', "Batas minimum bawaan barang baru diubah {$old} → {$value}", null, ['from' => $old, 'to' => $value]);
        }
        Setting::set(Setting::DEFAULT_MIN_STOCK, max(0, $value));
    }

    /**
     * Perbarui batas minimum banyak barang sekaligus. Hanya baris yang nilainya
     * berubah yang di-update. Mengembalikan jumlah barang yang berubah.
     *
     * @param  array<int, int>  $minStocks  [productId => min_stock]
     */
    public function updateMany(array $minStocks): int
    {
        return DB::transaction(function () use ($minStocks) {
            $changed = 0;
            $current = Product::whereKey(array_keys($minStocks))->pluck('min_stock', 'id');

            foreach ($minStocks as $id => $value) {
                if ($current->has($id) && (int) $current[$id] !== (int) $value) {
                    Product::whereKey($id)->update(['min_stock' => max(0, (int) $value)]);
                    app(NotificationService::class)->queueStockCheck((int) $id);
                    $changed++;
                }
            }

            if ($changed > 0) {
                AuditLog::record('stock.min_updated', 'stok', "Batas minimum stok {$changed} barang diubah", null,
                    ['changes' => collect($minStocks)->filter(fn ($v, $id) => $current->has($id) && (int) $current[$id] !== (int) $v)
                        ->map(fn ($v, $id) => ['from' => (int) $current[$id], 'to' => (int) $v])->all()]);
            }

            return $changed;
        });
    }

    /** Terapkan satu nilai ke semua barang di kategori (atau semua barang bila null). */
    public function applyToCategory(?string $category, int $value): int
    {
        $scope = Product::query()->when($category, fn ($q) => $q->whereHas('category', fn ($c) => $c->where('name', $category)));
        $ids = (clone $scope)->pluck('id')->all();
        $changed = $scope->update(['min_stock' => max(0, $value)]);
        app(NotificationService::class)->queueStockCheck(...$ids);

        AuditLog::record('stock.min_updated', 'stok', 'Batas minimum '.($category ? "kategori {$category}" : 'semua barang')." disetel ke {$value} ({$changed} barang)",
            null, ['category' => $category, 'value' => $value, 'count' => $changed]);

        return $changed;
    }
}
