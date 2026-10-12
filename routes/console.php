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
