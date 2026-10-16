<?php

use App\Jobs\DetectOverdueLoans;
use App\Jobs\SyncGuides;
use App\Models\Backup;
use App\Models\GuideSyncJob;
use App\Models\Loan;
use App\Models\User;
use App\Services\BackupService;
use App\Services\CouponService;
use App\Services\GuideMetadataService;
use App\Services\GuideScanner;
use App\Services\GuideSyncRecorder;
use App\Services\HealthCheckService;
use App\Services\ImpersonationService;
use App\Services\LoanAuditGuard;
use App\Services\LoanOverdueDetector;
use App\Services\LoanPaymentService;
use App\Services\NotificationService;
use App\Services\RetentionService;
use App\Services\SupplierBillService;
use App\Services\UserService;
use App\Support\Rupiah;
use App\Support\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jaring pengaman: selaraskan peringatan stok menipis/habis dengan kondisi barang
// (biasanya sudah otomatis saat stok berubah). Jalankan scheduler: `php artisan schedule:work`
// atau cron `* * * * * php artisan schedule:run`.
Artisan::command('notifikasi:stok', function () {
    $created = app(NotificationService::class)->syncStockAlerts();
    $this->info("Peringatan stok diperbarui: {$created} notifikasi baru.");
})->purpose('Selaraskan notifikasi stok menipis/habis dengan stok barang saat ini');

Schedule::command('notifikasi:stok')->dailyAt('06:30');

// Bersihkan data lewat masa retensi (pengaturan Keamanan & retensi): data master terhapus lunak,
// berkas ekspor laporan (baris & berkas di disk privat), dan notifikasi lama yang sudah dibaca.
Artisan::command('retensi:bersihkan {--uji : Hanya hitung tanpa menghapus}', function () {
    $dry = (bool) $this->option('uji');
    $counts = app(RetentionService::class)->run($dry);
    $this->table(['Data', $dry ? 'Akan dibersihkan' : 'Dibersihkan'],
        collect($counts)->map(fn ($n, $k) => [RetentionService::LABELS[$k], $n])->values()->all());
    $this->info($dry ? 'Uji coba: tidak ada data yang dihapus.' : 'Pembersihan masa retensi selesai.');
})->purpose('Hapus permanen data yang melewati masa retensi');

Schedule::command('retensi:bersihkan')->dailyAt('02:00');

Artisan::command('kupon:hitung-ulang', function () {
    $changed = app(CouponService::class)->recount();
    foreach ($changed as $code => $c) {
        $this->line("{$code}: {$c['from']} → {$c['to']}");
    }
    $this->info($changed ? count($changed).' kupon diselaraskan.' : 'Semua penghitung kupon sudah sesuai.');
})->purpose('Selaraskan penghitung pemakaian kupon dengan riwayat transaksi');

// Periksa keaslian jejak audit setiap malam; baris yang diubah langsung di database memicu notifikasi Admin.
Schedule::command('audit:verifikasi')->dailyAt('02:30');
// Pemeriksaan cepat tiap jam untuk catatan peran & impersonasi (termasuk rantai mulai–selesai sesi 7 hari terakhir).
Schedule::command('audit:verifikasi', ['--cepat'])->hourlyAt(17)->withoutOverlapping();

// Hitung ulang pinjaman: tandai angsuran lewat jatuh tempo (menunggak) dan selaraskan sisa/status lunas dengan
// pembayaran sah. Penyimpangan dicatat di riwayat perubahan angsuran & jejak audit.
Artisan::command('pinjaman:hitung-ulang {--kode= : Hanya satu pinjaman (kode PJM-…)} {--uji : Hitung & laporkan tanpa menyimpan}', function () {
    $svc = app(LoanPaymentService::class);
    $dry = (bool) $this->option('uji');
    if ($code = $this->option('kode')) {
        $loan = Loan::where('code', $code)->first();
        if (! $loan) {
            $this->error("Pinjaman {$code} tidak ditemukan.");

            return 1;
        }
        if ($dry) {
            DB::beginTransaction();
        }
        try {
            $row = $svc->recalculate($loan);
        } finally {
            if ($dry) {
                DB::rollBack();
            }
        }
        $rows = $row['changed'] ? [$row] : [];
    } else {
        $rows = $svc->recalculateAll($dry);
    }
    $fmt = fn ($s) => (Loan::STATUSES[$s['status']] ?? $s['status']).' · '.Rupiah::format($s['outstanding']);
    if ($rows) {
        $this->table(['Pinjaman', 'Sebelum', 'Sesudah', 'Angsuran diselaraskan'], array_map(fn ($r) => [$r['code'], $fmt($r['before']), $fmt($r['after']), $r['installments_fixed']], $rows));
    }
    $this->info(($dry ? 'Uji coba (tidak disimpan): ' : '').($rows ? count($rows).' pinjaman berubah.' : 'Semua pinjaman sudah sesuai.'));
})->purpose('Hitung ulang sisa, status menunggak, dan status lunas pinjaman');

Schedule::command('pinjaman:hitung-ulang')->dailyAt('00:15');

// Periksa rantai HMAC log audit pinjaman tiap malam; temuan → jejak audit + notifikasi Admin & Pengurus.
Artisan::command('pinjaman:verifikasi-audit', function () {
    $r = app(LoanAuditGuard::class)->check();
    $this->info("Diperiksa {$r['checked']} mata rantai.");
    if ($r['valid']) {
        $this->info('Rantai jejak audit pinjaman utuh.');

        return 0;
    }
    $this->error('Rantai tidak utuh: '.LoanAuditGuard::describe($r).'.');

    return 1;
})->purpose('Periksa keaslian rantai HMAC log audit pinjaman');

Schedule::command('pinjaman:verifikasi-audit')->dailyAt('02:40');

// Konsistensi peran petugas (pivot user_roles vs peran utama users.role_id); --perbaiki untuk membetulkan.
Artisan::command('pengguna:periksa-peran {--perbaiki : Betulkan pivot yang kurang & peran utama yang tidak sesuai}', function () {
    $r = app(UserService::class)->roleConsistency((bool) $this->option('perbaiki'));
    $this->line('Peran utama belum di pivot: '.count($r['missing_pivot']).' · peran utama tidak sesuai prioritas: '.count($r['wrong_primary']).' · tanpa peran: '.count($r['no_role']));
    if ($r['no_role']) {
        $this->warn('Akun tanpa peran (atur lewat halaman Pengguna): '.User::whereIn('id', $r['no_role'])->pluck('email')->join(', '));
    }
    if ($this->option('perbaiki')) {
        $this->info("{$r['fixed']} perbaikan diterapkan.");
    }
    $bad = $r['missing_pivot'] || $r['wrong_primary'] || $r['no_role'];

    return $bad && ! $this->option('perbaiki') ? 1 : 0;
})->purpose('Periksa konsistensi peran petugas (multi-peran)');

// Deteksi angsuran lewat jatuh tempo: tandai menunggak, catat jejak audit & kabari pengurus (job di antrean notifikasi).
Artisan::command('pinjaman:deteksi-tunggakan', function () {
    $r = app(LoanOverdueDetector::class)->run();
    foreach ($r['overdue'] as $code) {
        $this->line("Menunggak: {$code}");
    }
    foreach ($r['recovered'] as $code) {
        $this->line("Kembali lancar: {$code}");
    }
    $this->info("{$r['checked']} pinjaman diperiksa · ".count($r['overdue']).' baru menunggak · '.count($r['recovered']).' kembali lancar · '.$r['installments'].' angsuran diperbarui.');
})->purpose('Deteksi angsuran pinjaman yang lewat jatuh tempo dan tandai menunggak');

Schedule::job(new DetectOverdueLoans)->dailyAt('00:05')->name('pinjaman:deteksi-tunggakan')->withoutOverlapping();

// Pengingat hutang supplier yang jatuh tempo ≤ 3 hari atau sudah lewat (notifikasi untuk Pengurus & Admin).
Artisan::command('hutang:ingatkan', function () {
    $n = app(SupplierBillService::class)->remind();
    $this->info("Pengingat hutang: {$n} notifikasi baru.");
})->purpose('Ingatkan hutang supplier yang jatuh tempo');

Schedule::command('hutang:ingatkan')->dailyAt('06:45');

// Cadangan data: arsip zip (database.json portabel, pg_dump bila PostgreSQL, logo & QRIS) di disk privat.
Artisan::command('cadangan:buat {--jenis=lengkap : lengkap | basis data} {--catatan= : Catatan singkat} {--manual : Tandai sebagai cadangan manual}', function () {
    $type = (string) $this->option('jenis');
    if (! array_key_exists($type, Backup::TYPES)) {
        $this->error('Jenis harus "lengkap" atau "basis data".');

        return 1;
    }
    $b = app(BackupService::class)->run($type, $this->option('manual') ? 'manual' : 'terjadwal', $this->option('catatan'));
    if ($b->status !== 'berhasil') {
        $this->error("Cadangan gagal: {$b->error}");

        return 1;
    }
    $this->info("Cadangan {$b->name} dibuat (".SystemHealth::bytes($b->size).", {$b->duration_s} dtk).");

    return 0;
})->purpose('Buat cadangan data koperasi sekarang');

Schedule::command('cadangan:buat')->dailyAt(config('koperasi.backup.time', '23:00'))
    ->when(fn () => (bool) config('koperasi.backup.enabled', true))->withoutOverlapping();

Artisan::command('cadangan:periksa', function () {
    $this->info(app(BackupService::class)->checkStale() ? 'Peringatan cadangan terlambat dikirim ke Admin.' : 'Cadangan terbaru masih dalam batas.');
})->purpose('Peringatkan Admin bila tidak ada cadangan berhasil dalam 26 jam');

Schedule::command('cadangan:periksa')->dailyAt('08:00');

// Denyut penjadwal untuk pemantauan kesehatan (Sistem → Kesehatan).
Schedule::call(fn () => Cache::put('health:scheduler_heartbeat', now()->toDateTimeString(), now()->addDay()))
    ->everyMinute()->name('health-heartbeat');

// Pemeliharaan antrean: hapus catatan pekerjaan gagal & batch lama.
Schedule::command('queue:prune-failed', ['--hours' => config('koperasi.failed_jobs_hours', 720)])->dailyAt('03:00');
Schedule::command('queue:prune-batches', ['--hours' => 48])->dailyAt('03:05');

// Hosting tanpa pekerja antrean permanen (KOPERASI_QUEUE_VIA_CRON=true): cron `schedule:run` tiap menit sekaligus
// memproses antrean sampai kosong. Kunci 20 menit > job terlama (cadangan 900 dtk) agar tidak berjalan ganda.
Schedule::command('queue:work', [config('koperasi.queue_worker'), '--stop-when-empty', '--tries=3', '--max-time=55'])
    ->everyMinute()->when(fn () => (bool) config('koperasi.queue_via_cron'))
    ->name('antrean-via-cron')->withoutOverlapping(20);

// Riwayat kesehatan sistem: cuplikan tiap 5 menit (Sistem → Kesehatan: strip 24 jam & gangguan).
Artisan::command('kesehatan:periksa', function () {
    $row = HealthCheckService::record();
    $this->info("Kesehatan sistem: {$row->status} ({$row->duration_ms} ms).");
})->purpose('Periksa & catat kesehatan sistem');

Schedule::command('kesehatan:periksa')->everyFiveMinutes()->withoutOverlapping();

// Sesi impersonasi yang ditinggal (peramban ditutup tanpa "Kembali ke akun saya") ditutup saat lewat batas waktunya
// dan dicatat impersonation.ended (kedaluwarsa) di jejak audit.
Artisan::command('impersonasi:tutup-kedaluwarsa', function () {
    $n = app(ImpersonationService::class)->closeExpired();
    $this->info("{$n} sesi impersonasi kedaluwarsa ditutup.");
})->purpose('Tutup & catat sesi impersonasi yang lewat batas waktu');

Schedule::command('impersonasi:tutup-kedaluwarsa')->everyFiveMinutes()->withoutOverlapping();

// Susun ulang Pusat Panduan dari konfigurasi menu/halaman/izin (panduan yang disunting manual tidak ditimpa).
Artisan::command('panduan:susun', function () {
    ['stats' => $s] = app(GuideSyncRecorder::class)->run('manual', null, 'Disusun ulang lewat perintah panduan:susun');
    $this->info('Panduan disusun: '.count($s['created']).' baru, '.count($s['updated']).' diperbarui, '
        .count($s['unchanged']).' tetap, '.count($s['skipped_manual']).' manual dilewati.');
})->purpose('Susun ulang panduan otomatis dari konfigurasi menu');

// Sinkronisasi Pusat Panduan otomatis: periksa perubahan menu/halaman/izin tiap 5 menit (murah, tanpa menulis bila
// tidak berubah) → antre SyncGuides dengan pemicu menu_changed/permission_changed; penyusunan ulang menyeluruh harian.
Artisan::command('panduan:periksa {--antre : Antre sinkronisasi bila ada perubahan (bawaan hanya melapor)}', function () {
    $diff = app(GuideMetadataService::class)->diff();
    if ($diff['first_scan']) {
        app(GuideMetadataService::class)->capture();
        $this->info('Garis dasar metadata menu & izin disimpan.');

        return 0;
    }
    $trigger = GuideMetadataService::triggerFor($diff);
    if (! $trigger) {
        $this->info('Tidak ada perubahan menu/izin.');

        return 0;
    }
    $this->warn('Berubah: '.collect($diff['changed'])->map(fn ($a, $m) => $m.' ('.implode(', ', $a).')')->merge(array_map(fn ($m) => "{$m} (baru)", $diff['added']))
        ->merge(array_map(fn ($m) => "{$m} (hilang)", $diff['removed']))->join(', '));
    if ($this->option('antre')) {
        $pending = GuideSyncJob::whereIn('status', ['queued', 'running'])->where('trigger', $trigger)->exists();
        $pending ? $this->line('Sinkronisasi serupa sudah mengantre.') : $this->info('Sinkronisasi diantre (#'.SyncGuides::enqueue($trigger, 'Perubahan terdeteksi otomatis')->id.').');
    }

    return 0;
})->purpose('Periksa perubahan menu/izin untuk panduan, opsional antre sinkronisasi');

Schedule::command('panduan:periksa', ['--antre'])->everyFiveMinutes()->withoutOverlapping()->name('panduan:periksa');
Schedule::call(fn () => SyncGuides::enqueue('scheduled', 'Sinkronisasi harian'))->dailyAt('03:30')->name('panduan:sinkron-harian')->withoutOverlapping();

// Pindai menu, halaman & izin peran (bahan panduan otomatis) dan laporkan temuan konfigurasi. --ketat: gagal bila ada temuan.
Artisan::command('panduan:pindai {--json : Keluaran JSON (fakta per menu, izin per peran, temuan)} {--ketat : Kode keluar 1 bila ada temuan}', function () {
    $scanner = app(GuideScanner::class);
    $facts = $scanner->scan();
    $issues = $scanner->issues($facts);
    if ($this->option('json')) {
        $this->line(json_encode(['menus' => $facts, 'roles' => $scanner->roles(), 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } else {
        $this->table(['Menu', 'Peran', 'Halaman', 'Khusus peran', 'Berparameter'], collect($facts)->map(fn ($f) => [
            $f['label'], implode(', ', $f['roles']) ?: '—', count($f['pages']), count(array_filter($f['pages'], fn ($p) => $p['roles'] !== [])), $f['skipped_pages'],
        ])->values()->all());
        $s = $scanner->summary($facts);
        $this->info("{$s['menus']} menu, {$s['pages']} halaman, {$s['roles']} peran, {$s['issues']} temuan.");
        foreach ($issues as $i) {
            $this->warn('- '.$i['text']);
        }
    }

    return $this->option('ketat') && $issues ? 1 : 0;
})->purpose('Pindai menu, halaman & izin peran untuk panduan');
