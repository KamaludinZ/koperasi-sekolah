<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Peran admin/kasir/pengurus sudah diisi oleh migrasi.
        $this->call(ProductSeeder::class);

        // Akun demo, anggota, & riwayat contoh hanya untuk pengembangan.
        // Produksi: buat admin dengan `php artisan koperasi:buat-admin`.
        if (app()->environment('local')) {
            $this->call([UserSeeder::class, MemberSeeder::class, TransactionSeeder::class, ActivityLogSeeder::class]);
        }
    }
}
