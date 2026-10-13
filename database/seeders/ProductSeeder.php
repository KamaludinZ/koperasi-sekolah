<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\AuditLog;
use App\Services\NotificationService;
use App\Support\MockData;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/** Barang & kategori contoh untuk koperasi sekolah. */
class ProductSeeder extends Seeder
{
    // Data contoh adalah kondisi awal, bukan mutasi stok — jangan catat riwayat.
    use WithoutModelEvents;

    /** Data contoh dibuat tanpa jejak audit perubahan data master. */
    public function run(): void
    {
        AuditLog::quietly(fn () => $this->seedRows());
    }

    private function seedRows(): void
    {
        $categories = collect(MockData::categories())
            ->values()
            ->mapWithKeys(fn ($name, $i) => [$name => Category::firstOrCreate(['name' => $name],
                ['position' => $i + 1, 'color' => Category::COLORS[$i % count(Category::COLORS)]])->id]);

        foreach (MockData::seedProducts() as $p) {
            Product::updateOrCreate(['code' => $p['code']], [
                'name' => $p['name'],
                'category_id' => $categories[$p['category']],
                'price' => $p['price'],
                'cost_price' => $p['cost_price'],
                'stock' => $p['stock'],
                'min_stock' => $p['min_stock'],
                'unit' => $p['unit'],
                'is_active' => true,
            ]);
        }

        // Event model dimatikan, jadi peringatan stok barang contoh dibuat di sini.
        app(NotificationService::class)->syncStockAlerts();
    }
}
