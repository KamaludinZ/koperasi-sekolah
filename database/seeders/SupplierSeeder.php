<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Supplier;
use App\Services\AuditLog;
use Illuminate\Database\Seeder;

/** Supplier contoh beserta kategori barang yang dipasok. */
class SupplierSeeder extends Seeder
{
    public const SUPPLIERS = [
        ['CV Sinar Ilmu', 'Bu Lestari', '0341-555-120', 'Jl. Kawi 12, Malang', 30, ['Alat Tulis'], true, 'Minimal pesan Rp300.000, gratis antar.'],
        ['UD Berkah Pangan', 'Pak Rahmat', '0812-3300-4411', 'Pasar Besar Blok C-7, Malang', 7, ['Makanan', 'Minuman'], true, 'Antar setiap Senin & Kamis pagi.'],
        ['Konveksi Amanah', 'Bu Siti', '0813-2211-7788', 'Jl. Sukarno-Hatta 40, Malang', 14, ['Seragam'], true, null],
        ['Toko Grosir Jaya', 'Koh Ahong', '0341-555-987', 'Jl. Pasar Besar 5, Malang', 0, ['Makanan', 'Alat Tulis'], false, 'Tunai saja. Tidak dipakai lagi sejak harga naik.'],
    ];

    public function run(): void
    {
        if (Supplier::exists()) {
            return;
        }
        AuditLog::quietly(function () {
            foreach (self::SUPPLIERS as [$name, $contact, $phone, $address, $terms, $categories, $active, $note]) {
                $s = Supplier::create(['name' => $name, 'contact' => $contact, 'phone' => $phone, 'address' => $address,
                    'terms_days' => $terms, 'is_active' => $active, 'note' => $note]);
                $s->categories()->sync(Category::whereIn('name', $categories)->pluck('id'));
            }
        });
    }
}
