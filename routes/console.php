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

// Pengingat hutang supplier yang jatuh tempo ≤ 3 hari atau sudah lewat (notifikasi untuk Pengurus & Admin).
Artisan::command('hutang:ingatkan', function () {
    $n = app(\App\Services\SupplierBillService::class)->remind();
    $this->info("Pengingat hutang: {$n} notifikasi baru.");
})->purpose('Ingatkan hutang supplier yang jatuh tempo');

Schedule::command('hutang:ingatkan')->dailyAt('06:45');

// Cadangan data: arsip zip (database.json portabel, pg_dump bila PostgreSQL, logo & QRIS) di disk privat.
Artisan::command('cadangan:buat {--jenis=lengkap : lengkap | basis data} {--catatan= : Catatan singkat} {--manual : Tandai sebagai cadangan manual}', function () {
    $type = (string) $this->option('jenis');
    if (! array_key_exists($type, \App\Models\Backup::TYPES)) {
        $this->error('Jenis harus "lengkap" atau "basis data".');

        return 1;
    }
    $b = app(\App\Services\BackupService::class)->run($type, $this->option('manual') ? 'manual' : 'terjadwal', $this->option('catatan'));
    if ($b->status !== 'berhasil') {
        $this->error("Cadangan gagal: {$b->error}");

        return 1;
    }
    $this->info("Cadangan {$b->name} dibuat (".\App\Support\SystemHealth::bytes($b->size).", {$b->duration_s} dtk).");

    return 0;
})->purpose('Buat cadangan data koperasi sekarang');

Schedule::command('cadangan:buat')->dailyAt(config('koperasi.backup.time', '23:00'))
    ->when(fn () => (bool) config('koperasi.backup.enabled', true))->withoutOverlapping();

Artisan::command('cadangan:periksa', function () {
    $this->info(app(\App\Services\BackupService::class)->checkStale() ? 'Peringatan cadangan terlambat dikirim ke Admin.' : 'Cadangan terbaru masih dalam batas.');
})->purpose('Peringatkan Admin bila tidak ada cadangan berhasil dalam 26 jam');

Schedule::command('cadangan:periksa')->dailyAt('08:00');

// Denyut penjadwal untuk pemantauan kesehatan (Sistem → Kesehatan).
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put('health:scheduler_heartbeat', now()->toDateTimeString(), now()->addDay()))
    ->everyMinute()->name('health-heartbeat');
