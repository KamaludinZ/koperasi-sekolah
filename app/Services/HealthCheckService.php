<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\HealthCheck;
use App\Models\Notification;
use App\Models\RestoreTest;
use App\Support\BackupStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pemeriksaan kesehatan komponen sistem (semua data asli): basis data (latensi), cache (baca/tulis),
 * penyimpanan (sisa disk & izin tulis), antrean (tertunda, gagal 24 jam, denyut pekerja), penjadwal
 * (denyut tiap menit), cadangan (dari tabel backups), dan aplikasi (versi, mode debug); angka ringkas;
 * serta gangguan 30 hari terakhir (cadangan/uji pemulihan gagal, tugas antrean gagal).
 * Status tiap pemeriksaan: ok | peringatan | gangguan. Dipakai halaman Sistem → Kesehatan & GET /sistem/kesehatan/data.
 */
class HealthCheckService
{
    public const STATUSES = ['ok' => 'Normal', 'peringatan' => 'Perlu perhatian', 'gangguan' => 'Gangguan'];

    /** Denyut penjadwal/pekerja lebih tua dari ini (menit) dianggap berhenti. */
    public const HEARTBEAT_MINUTES = 5;

    /** Ruang disk tersisa (%) di bawah ini = peringatan / gangguan. */
    public const DISK_WARN = 20;

    public const DISK_FAIL = 5;

    /** @return array{status: string, checked_at: string, checks: list<array>, metrics: array, incidents: list<array>} */
    public static function report(bool $withHistory = true): array
    {
        $checks = [self::database(), self::cache(), self::storage(), self::queue(), self::scheduler(), self::backup(), self::app()];
        $worst = collect($checks)->pluck('status')->contains('gangguan') ? 'gangguan'
            : (collect($checks)->pluck('status')->contains('peringatan') ? 'peringatan' : 'ok');

        return [
            'status' => $worst,
            'checked_at' => now()->toDateTimeString(),
            'checks' => $checks,
            'metrics' => self::metrics(),
            'incidents' => $withHistory ? self::incidents() : [],
        ];
    }

    /** Peringkat status (makin besar makin buruk). */
    public const RANK = ['ok' => 0, 'peringatan' => 1, 'gangguan' => 2];

    /** Simpan cuplikan pemeriksaan saat ini ke riwayat; bersihkan riwayat > 30 hari. */
    public static function record(): HealthCheck
    {
        $t = microtime(true);
        $r = self::report(withHistory: false);
        $prev = HealthCheck::orderByDesc('created_at')->orderByDesc('id')->first();
        $row = HealthCheck::create([
            'status' => $r['status'],
            'results' => collect($r['checks'])->mapWithKeys(fn ($c) => [$c['key'] => ['status' => $c['status'], 'detail' => $c['detail']]])->all(),
            'duration_ms' => (int) round((microtime(true) - $t) * 1000),
        ]);
        HealthCheck::where('created_at', '<', now()->subDays(30))->delete();
        self::alert($r['checks'], $prev?->results ?? []);

        return $row;
    }

    /**
     * Peringatan lewat notifikasi Admin: komponen yang memburuk (normal → perhatian/gangguan, atau
     * perhatian → gangguan) mendapat satu notifikasi; saat kembali normal notifikasi itu dihapus dan
     * diganti pemberitahuan pulih.
     *
     * @param  list<array{key: string, label: string, status: string, detail: string}>  $checks
     * @param  array<string, array{status: string}>  $previous
     */
    private static function alert(array $checks, array $previous): void
    {
        $url = route('sistem', [], false);
        foreach ($checks as $c) {
            $before = $previous[$c['key']]['status'] ?? 'ok';
            $key = "health-alert-{$c['key']}";
            if ($c['status'] !== 'ok' && self::RANK[$c['status']] > self::RANK[$before]) {
                Notification::where('dedupe_key', $key)->delete();
                Notification::create(['dedupe_key' => $key, 'type' => 'sistem', 'target' => 'admin', 'url' => $url,
                    'title' => ($c['status'] === 'gangguan' ? 'Gangguan: ' : 'Perlu perhatian: ').$c['label'], 'message' => $c['detail']]);
            } elseif ($c['status'] === 'ok' && $before !== 'ok') {
                Notification::where('dedupe_key', $key)->delete();
                Notification::updateOrCreate(['dedupe_key' => "health-ok-{$c['key']}-".now()->format('YmdHi')],
                    ['type' => 'sistem', 'target' => 'admin', 'url' => $url, 'title' => "{$c['label']} kembali normal", 'message' => $c['detail']]);
            }
        }
    }

    /**
     * Strip 24 jam per komponen: status terburuk tiap jam (null = tidak ada data).
     *
     * @return array<string, list<array{hour: string, status: ?string}>>
     */
    public static function history(int $hours = 24): array
    {
        if (! Schema::hasTable('health_checks')) {
            return [];
        }
        $start = now()->startOfHour()->subHours($hours - 1);
        $rows = HealthCheck::where('created_at', '>=', $start)->orderBy('created_at')->get(['results', 'created_at']);
        $out = [];
        foreach ($rows as $row) {
            $h = $row->created_at->format('Y-m-d H:00');
            foreach ($row->results as $key => $res) {
                $prev = $out[$key][$h] ?? null;
                if ($prev === null || self::RANK[$res['status']] > self::RANK[$prev]) {
                    $out[$key][$h] = $res['status'];
                }
            }
        }

        return collect($out)->map(function ($byHour) use ($start, $hours) {
            return collect(range(0, $hours - 1))->map(fn ($i) => ['hour' => $start->copy()->addHours($i)->format('Y-m-d H:00'),
                'status' => $byHour[$start->copy()->addHours($i)->format('Y-m-d H:00')] ?? null])->all();
        })->all();
    }

    /** Gangguan dari riwayat: rentang waktu komponen tidak normal (mulai → pulih), 30 hari terakhir. */
    private static function historyIncidents(): array
    {
        if (! Schema::hasTable('health_checks')) {
            return [];
        }
        $open = [];
        $out = [];
        foreach (HealthCheck::where('created_at', '>=', now()->subDays(30))->orderBy('created_at')->orderBy('id')->get() as $row) {
            foreach ($row->results as $key => $res) {
                if ($res['status'] !== 'ok' && ! isset($open[$key])) {
                    $open[$key] = ['at' => $row->created_at->toDateTimeString(), 'check' => $key, 'status' => $res['status'], 'message' => $res['detail'], 'resolved_at' => null];
                } elseif ($res['status'] !== 'ok' && self::RANK[$res['status']] > self::RANK[$open[$key]['status']]) {
                    $open[$key]['status'] = $res['status'];
                    $open[$key]['message'] = $res['detail'];
                } elseif ($res['status'] === 'ok' && isset($open[$key])) {
                    $out[] = [...$open[$key], 'resolved_at' => $row->created_at->toDateTimeString()];
                    unset($open[$key]);
                }
            }
        }

        return [...$out, ...array_values($open)];
    }

    private static function check(string $key, string $label, string $status, string $detail, array $meta = []): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail, 'meta' => $meta];
    }

    private static function database(): array
    {
        try {
            $t = microtime(true);
            DB::select('SELECT 1');
            $ms = (int) round((microtime(true) - $t) * 1000);

            return self::check('database', 'Basis data', $ms > 500 ? 'peringatan' : 'ok',
                $ms > 500 ? "Lambat merespons ({$ms} ms)." : "Terhubung, respons {$ms} ms.", ['driver' => DB::getDriverName(), 'latency_ms' => $ms]);
        } catch (Throwable $e) {
            return self::check('database', 'Basis data', 'gangguan', 'Tidak bisa terhubung ke basis data.');
        }
    }

    private static function cache(): array
    {
        try {
            $key = 'health:'.Str::random(8);
            Cache::put($key, 'ok', 10);
            $ok = Cache::pull($key) === 'ok';

            return self::check('cache', 'Cache', $ok ? 'ok' : 'gangguan', $ok ? 'Baca & tulis cache berjalan.' : 'Cache tidak menyimpan data.', ['store' => config('cache.default')]);
        } catch (Throwable) {
            return self::check('cache', 'Cache', 'gangguan', 'Cache tidak bisa dipakai.');
        }
    }

    private static function storage(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path) ?: 0;
        $total = @disk_total_space($path) ?: 0;
        $pct = $total > 0 ? (int) round($free / $total * 100) : 0;
        $writable = is_writable(storage_path('app'));
        $status = ! $writable || $pct < self::DISK_FAIL ? 'gangguan' : ($pct < self::DISK_WARN ? 'peringatan' : 'ok');

        return self::check('storage', 'Penyimpanan', $status,
            ! $writable ? 'Folder penyimpanan tidak bisa ditulis.' : "Sisa {$pct}% (".self::bytes($free).' dari '.self::bytes($total).').',
            ['free' => (int) $free, 'total' => (int) $total, 'free_pct' => $pct]);
    }

    private static function queue(): array
    {
        if (! Schema::hasTable('jobs')) {
            return self::check('queue', 'Antrean latar', 'peringatan', 'Tabel antrean belum tersedia.');
        }
        $pending = DB::table('jobs')->count();
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        $oldest = DB::table('jobs')->min('created_at');
        $waitMin = $oldest ? (int) floor((time() - (int) $oldest) / 60) : 0;
        $sync = config('queue.default') === 'sync';
        $beat = Cache::get('health:worker_heartbeat');
        $workerDown = ! $sync && $pending > 0 && (! $beat || Carbon::parse($beat)->lt(now()->subMinutes(self::HEARTBEAT_MINUTES)));
        $status = $workerDown ? 'gangguan' : ($failed > 0 || $waitMin >= 30 ? 'peringatan' : 'ok');

        return self::check('queue', 'Antrean latar', $status,
            $workerDown ? 'Pekerja antrean tidak aktif — ada tugas tertunda. Jalankan `queue:work` di server.'
                : ($failed ? "{$failed} tugas gagal dalam 24 jam." : ($waitMin >= 30 ? "Tugas tertua menunggu {$waitMin} menit." : ($pending ? "{$pending} tugas menunggu diproses." : 'Tidak ada tugas yang tertunda.'))),
            ['pending' => $pending, 'failed_24h' => $failed, 'oldest_wait_min' => $waitMin, 'driver' => config('queue.default'), 'worker_seen' => $beat, 'sync' => $sync]);
    }

    private static function scheduler(): array
    {
        $last = Cache::get('health:scheduler_heartbeat');
        if (! $last) {
            return self::check('scheduler', 'Penjadwal tugas', 'peringatan', 'Belum ada denyut penjadwal — pastikan cron `schedule:run` tiap menit sudah dipasang.', ['last_run' => null]);
        }
        $mins = (int) floor(Carbon::parse($last)->diffInMinutes(now(), true));

        return self::check('scheduler', 'Penjadwal tugas', $mins > self::HEARTBEAT_MINUTES ? 'gangguan' : 'ok',
            $mins > self::HEARTBEAT_MINUTES ? "Tidak berjalan sejak {$mins} menit lalu — periksa cron server." : 'Berjalan tiap menit (retensi, pengingat hutang, cadangan).',
            ['last_run' => $last]);
    }

    private static function backup(): array
    {
        $s = BackupStatus::summarize(self::backupRows());
        $last = $s['last_ok'];

        return self::check('backup', 'Cadangan data', $s['status'],
            $last ? 'Cadangan terakhir '.Carbon::parse($last['created_at'])->diffForHumans().', '.self::bytes($last['size']).'.'.($s['failed'] ? " {$s['failed']} gagal dalam 14 malam." : '') : 'Belum ada cadangan yang berhasil.',
            ['last_at' => $last['created_at'] ?? null, 'size' => $last['size'] ?? 0, 'failed' => $s['failed']]);
    }

    /** Baris cadangan 30 hari terakhir dalam bentuk halaman (terbaru dulu). */
    public static function backupRows(): array
    {
        return Schema::hasTable('backups')
            ? Backup::with('creator:id,name')->where('started_at', '>=', now()->subDays(30))->latestFirst()->get()->map->toRow()->all()
            : [];
    }

    private static function app(): array
    {
        $debug = (bool) config('app.debug');

        return self::check('app', 'Aplikasi', $debug && app()->environment('production') ? 'peringatan' : 'ok',
            'Laravel '.app()->version().' · PHP '.PHP_VERSION.' · '.app()->environment().($debug ? ' · mode debug' : ''),
            ['laravel' => app()->version(), 'php' => PHP_VERSION, 'env' => app()->environment(), 'debug' => $debug]);
    }

    /** Angka ringkas: ukuran data & aktivitas hari ini. */
    private static function metrics(): array
    {
        $count = fn (string $table) => Schema::hasTable($table) ? DB::table($table)->count() : 0;

        return [
            'transactions_today' => Schema::hasTable('transactions') ? DB::table('transactions')->where('created_at', '>=', today())->count() : 0,
            'transactions' => $count('transactions'),
            'products' => $count('products'),
            'members' => $count('members'),
            'activity_logs' => $count('activity_logs'),
            'sessions_active' => Schema::hasTable('sessions') ? DB::table('sessions')->where('last_activity', '>=', now()->subMinutes(15)->timestamp)->whereNotNull('user_id')->count() : 0,
        ];
    }

    /** Gangguan 30 hari terakhir: cadangan/uji pemulihan gagal & tugas antrean gagal (per hari), terbaru dulu. */
    private static function incidents(): array
    {
        $since = now()->subDays(30);
        $rows = collect();
        if (Schema::hasTable('backups')) {
            foreach (Backup::where('status', 'gagal')->where('started_at', '>=', $since)->get() as $b) {
                $ok = Backup::succeeded()->where('started_at', '>', $b->started_at)->min('finished_at');
                $rows->push(['at' => $b->started_at->toDateTimeString(), 'check' => 'backup', 'status' => 'gangguan',
                    'message' => 'Cadangan gagal: '.mb_strimwidth((string) strtok((string) $b->error, "\n"), 0, 120, '…'), 'resolved_at' => $ok ? Carbon::parse($ok)->toDateTimeString() : null]);
            }
        }
        if (Schema::hasTable('restore_tests')) {
            foreach (RestoreTest::where('status', 'gagal')->where('started_at', '>=', $since)->get() as $t) {
                $rows->push(['at' => $t->started_at->toDateTimeString(), 'check' => 'backup', 'status' => 'peringatan',
                    'message' => 'Uji pemulihan gagal: '.mb_strimwidth((string) strtok((string) $t->error, "\n"), 0, 120, '…'), 'resolved_at' => null]);
            }
        }
        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')->where('failed_at', '>=', $since)->get(['failed_at'])
                ->groupBy(fn ($f) => substr((string) $f->failed_at, 0, 10))
                ->each(function ($g) use ($rows) {
                    $rows->push(['at' => (string) $g->max('failed_at'), 'check' => 'queue', 'status' => 'peringatan',
                        'message' => count($g).' tugas antrean gagal.', 'resolved_at' => null]);
                });
        }

        // Gangguan komponen dari riwayat pemeriksaan (antrean & cadangan sudah tercakup di atas).
        foreach (self::historyIncidents() as $i) {
            if (! in_array($i['check'], ['backup'], true)) {
                $rows->push($i);
            }
        }

        return $rows->sortByDesc('at')->take(10)->values()->all();
    }

    public static function bytes(int|float $b): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }

        return str_replace('.', ',', (string) round($b, $i >= 2 ? 1 : 0)).' '.$units[$i];
    }
}
