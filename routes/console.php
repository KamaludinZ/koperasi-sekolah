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

// Hitung ulang pinjaman: tandai angsuran lewat jatuh tempo (menunggak) dan selaraskan sisa/status lunas dengan
// pembayaran sah. Penyimpangan dicatat di riwayat perubahan angsuran & jejak audit.
Artisan::command('pinjaman:hitung-ulang {--kode= : Hanya satu pinjaman (kode PJM-…)} {--uji : Hitung & laporkan tanpa menyimpan}', function () {
    $svc = app(\App\Services\LoanPaymentService::class);
    $dry = (bool) $this->option('uji');
    if ($code = $this->option('kode')) {
        $loan = \App\Models\Loan::where('code', $code)->first();
        if (! $loan) {
            $this->error("Pinjaman {$code} tidak ditemukan.");

            return 1;
        }
        if ($dry) {
            \Illuminate\Support\Facades\DB::beginTransaction();
        }
        try {
            $row = $svc->recalculate($loan);
        } finally {
            if ($dry) {
                \Illuminate\Support\Facades\DB::rollBack();
            }
        }
        $rows = $row['changed'] ? [$row] : [];
    } else {
        $rows = $svc->recalculateAll($dry);
    }
    $fmt = fn ($s) => (\App\Models\Loan::STATUSES[$s['status']] ?? $s['status']).' · '.\App\Support\Rupiah::format($s['outstanding']);
    if ($rows) {
        $this->table(['Pinjaman', 'Sebelum', 'Sesudah', 'Angsuran diselaraskan'], array_map(fn ($r) => [$r['code'], $fmt($r['before']), $fmt($r['after']), $r['installments_fixed']], $rows));
    }
    $this->info(($dry ? 'Uji coba (tidak disimpan): ' : '').($rows ? count($rows).' pinjaman berubah.' : 'Semua pinjaman sudah sesuai.'));
})->purpose('Hitung ulang sisa, status menunggak, dan status lunas pinjaman');

Schedule::command('pinjaman:hitung-ulang')->dailyAt('00:15');

// Periksa rantai HMAC log audit pinjaman tiap malam; temuan → jejak audit + notifikasi Admin & Pengurus.
Artisan::command('pinjaman:verifikasi-audit', function () {
    $r = app(\App\Services\LoanAuditGuard::class)->check();
    $this->info("Diperiksa {$r['checked']} mata rantai.");
    if ($r['valid']) {
        $this->info('Rantai jejak audit pinjaman utuh.');

        return 0;
    }
    $this->error('Rantai tidak utuh: '.\App\Services\LoanAuditGuard::describe($r).'.');

    return 1;
})->purpose('Periksa keaslian rantai HMAC log audit pinjaman');

Schedule::command('pinjaman:verifikasi-audit')->dailyAt('02:40');

// Deteksi angsuran lewat jatuh tempo: tandai menunggak, catat jejak audit & kabari pengurus (job di antrean notifikasi).
Artisan::command('pinjaman:deteksi-tunggakan', function () {
    $r = app(\App\Services\LoanOverdueDetector::class)->run();
    foreach ($r['overdue'] as $code) {
        $this->line("Menunggak: {$code}");
    }
    foreach ($r['recovered'] as $code) {
        $this->line("Kembali lancar: {$code}");
    }
    $this->info("{$r['checked']} pinjaman diperiksa · ".count($r['overdue']).' baru menunggak · '.count($r['recovered']).' kembali lancar · '.$r['installments'].' angsuran diperbarui.');
})->purpose('Deteksi angsuran pinjaman yang lewat jatuh tempo dan tandai menunggak');

Schedule::job(new \App\Jobs\DetectOverdueLoans)->dailyAt('00:05')->name('pinjaman:deteksi-tunggakan')->withoutOverlapping();

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

// Pemeliharaan antrean: hapus catatan pekerjaan gagal & batch lama.
Schedule::command('queue:prune-failed', ['--hours' => config('koperasi.failed_jobs_hours', 720)])->dailyAt('03:00');
Schedule::command('queue:prune-batches', ['--hours' => 48])->dailyAt('03:05');

// Riwayat kesehatan sistem: cuplikan tiap 5 menit (Sistem → Kesehatan: strip 24 jam & gangguan).
Artisan::command('kesehatan:periksa', function () {
    $row = \App\Services\HealthCheckService::record();
    $this->info("Kesehatan sistem: {$row->status} ({$row->duration_ms} ms).");
})->purpose('Periksa & catat kesehatan sistem');

Schedule::command('kesehatan:periksa')->everyFiveMinutes()->withoutOverlapping();
