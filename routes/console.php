<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jaring pengaman: selaraskan peringatan stok menipis/habis dengan kondisi barang
// (biasanya sudah otomatis saat stok berubah). Jalankan scheduler: `php artisan schedule:work`
// atau cron `* * * * * php artisan schedule:run`.
Artisan::command('notifikasi:stok', function () {
    $created = app(\App\Services\NotificationService::class)->syncStockAlerts();
    $this->info("Peringatan stok diperbarui: {$created} notifikasi baru.");
})->purpose('Selaraskan notifikasi stok menipis/habis dengan stok barang saat ini');

Schedule::command('notifikasi:stok')->dailyAt('06:30');

// Bersihkan data lewat masa retensi (pengaturan Keamanan & retensi): data master terhapus lunak,
// berkas ekspor laporan (baris & berkas di disk privat), dan notifikasi lama yang sudah dibaca.
Artisan::command('retensi:bersihkan {--uji : Hanya hitung tanpa menghapus}', function () {
    $dry = (bool) $this->option('uji');
    $counts = app(\App\Services\RetentionService::class)->run($dry);
    $this->table(['Data', $dry ? 'Akan dibersihkan' : 'Dibersihkan'],
        collect($counts)->map(fn ($n, $k) => [\App\Services\RetentionService::LABELS[$k], $n])->values()->all());
    $this->info($dry ? 'Uji coba: tidak ada data yang dihapus.' : 'Pembersihan masa retensi selesai.');
})->purpose('Hapus permanen data yang melewati masa retensi');

Schedule::command('retensi:bersihkan')->dailyAt('02:00');

Artisan::command('kupon:hitung-ulang', function () {
    $changed = app(\App\Services\CouponService::class)->recount();
    foreach ($changed as $code => $c) {
        $this->line("{$code}: {$c['from']} → {$c['to']}");
    }
    $this->info($changed ? count($changed).' kupon diselaraskan.' : 'Semua penghitung kupon sudah sesuai.');
})->purpose('Selaraskan penghitung pemakaian kupon dengan riwayat transaksi');

// Periksa keaslian jejak audit setiap malam; baris yang diubah langsung di database memicu notifikasi Admin.
Schedule::command('audit:verifikasi')->dailyAt('02:30');
