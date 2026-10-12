<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Database\Seeder;

/**
 * Notifikasi contoh: peringatan stok dari barang yang menipis/habis saat ini
 * (NotificationService::syncStockAlerts) + pengumuman & info sistem.
 */
class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        app(NotificationService::class)->syncStockAlerts();

        foreach ([
            [3, 'pengumuman', 'semua', 'Libur Maulid Nabi', 'Koperasi tutup pada hari libur Maulid Nabi Muhammad SAW. Layanan buka kembali keesokan harinya pukul 07.00.'],
            [26, 'pengumuman', 'anggota', 'Gerakan menabung pekan ini', 'Ayo setor tabungan minimal Rp2.000 setiap hari Senin. Bawa buku tabungan ke kasir koperasi.'],
            [50, 'sistem', 'pengurus', 'Rekap penjualan bulan lalu siap', 'Rekap penjualan bulan lalu sudah bisa diunduh dari menu Laporan dalam format PDF atau Excel.'],
            [74, 'pengumuman', 'kasir', 'Seragam baru datang', 'Stok seragam olahraga ukuran S–XL sudah masuk. Pastikan harga di layar kasir sudah sesuai.'],
            [150, 'sistem', 'semua', 'Fitur Audit & Riwayat tersedia', 'Admin dan pengurus kini bisa melihat jejak login dan semua aktivitas petugas di menu Audit & Riwayat.'],
        ] as [$hours, $type, $target, $title, $message]) {
            Notification::firstOrCreate(['title' => $title, 'type' => $type], [
                'target' => $target, 'message' => $message,
                'created_at' => now()->subHours($hours), 'updated_at' => now()->subHours($hours),
            ]);
        }
    }
}
