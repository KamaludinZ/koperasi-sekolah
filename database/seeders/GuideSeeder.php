<?php

namespace Database\Seeders;

use App\Services\GuideGenerator;
use App\Services\GuideMetadataService;
use Illuminate\Database\Seeder;

/**
 * Isi awal Pusat Panduan lewat penyusun otomatis (GuideGenerator: katalog bawaan + menu/halaman/izin yang terbaca).
 * Aman dijalankan berulang: panduan baru ditambahkan, panduan otomatis diperbarui bila berubah (versi dicatat),
 * panduan yang sudah disunting manual tidak disentuh. Metadata menu & izin saat itu disimpan sebagai garis dasar
 * deteksi perubahan (guide_menu_snapshots).
 */
class GuideSeeder extends Seeder
{
    public function run(): void
    {
        app(GuideGenerator::class)->sync(null, 'Disusun dari katalog bawaan & konfigurasi menu');
        app(GuideMetadataService::class)->capture();
    }
}
