<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Services\NotificationService;
use App\Support\MockData;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/** Barang & kategori contoh untuk koperasi sekolah. */
class ProductSeeder extends Seeder
{
    // Data contoh adalah kondisi awal, bukan mutasi stok — jangan catat riwayat.
    use WithoutModelEvents;

    public function run(): void
    {
        $categories = collect(MockData::categories())
            ->mapWithKeys(fn ($name) => [$name => Category::firstOrCreate(['name' => $name])->id]);

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
