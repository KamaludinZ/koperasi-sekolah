<?php

namespace Database\Seeders;

use App\Services\GuideGenerator;
use Illuminate\Database\Seeder;

/**
 * Isi awal Pusat Panduan lewat penyusun otomatis (GuideGenerator: katalog bawaan + menu/halaman/izin yang terbaca).
 * Aman dijalankan berulang: panduan baru ditambahkan, panduan otomatis diperbarui bila berubah (versi dicatat),
 * panduan yang sudah disunting manual tidak disentuh.
 */
class GuideSeeder extends Seeder
{
    public function run(): void
    {
        app(GuideGenerator::class)->sync(null, 'Disusun dari katalog bawaan & konfigurasi menu');
    }
}
