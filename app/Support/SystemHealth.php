<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pemeriksaan kesehatan sistem untuk halaman Sistem → Kesehatan. Pemeriksaan yang murah dihitung langsung
 * (basis data, cache, penyimpanan, antrean, versi aplikasi); jadwal & cadangan memakai data tiruan sampai
 * backend pemantauan tersedia. Status tiap pemeriksaan: ok | peringatan | gangguan.
 *
 * Kontrak backend: GET /sistem/kesehatan → {status, checked_at, checks[], metrics, incidents[]}.
 */
class SystemHealth
{
    public const STATUSES = ['ok' => 'Normal', 'peringatan' => 'Perlu perhatian', 'gangguan' => 'Gangguan'];

    /** Ruang disk tersisa (%) di bawah ini = peringatan / gangguan. */
    public const DISK_WARN = 20;

    public const DISK_FAIL = 5;

    /** @return array{status: string, checked_at: string, checks: list<array>, metrics: array, incidents: list<array>} */
    public static function report(): array
    {
        $checks = [self::database(), self::cache(), self::storage(), self::queue(), self::scheduler(), self::backup(), self::app()];
        $worst = collect($checks)->pluck('status')->contains('gangguan') ? 'gangguan'
            : (collect($checks)->pluck('status')->contains('peringatan') ? 'peringatan' : 'ok');

        return [
            'status' => $worst,
            'checked_at' => now()->toDateTimeString(),
            'checks' => $checks,
            'metrics' => self::metrics(),
            'incidents' => self::incidents(),
        ];
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
        $status = $failed > 0 || $waitMin >= 30 ? 'peringatan' : 'ok';

        return self::check('queue', 'Antrean latar', $status,
            $failed ? "{$failed} tugas gagal dalam 24 jam." : ($waitMin >= 30 ? "Tugas tertua menunggu {$waitMin} menit." : ($pending ? "{$pending} tugas menunggu diproses." : 'Tidak ada tugas yang tertunda.')),
            ['pending' => $pending, 'failed_24h' => $failed, 'oldest_wait_min' => $waitMin, 'driver' => config('queue.default')]);
    }

    /** Tiruan: denyut penjadwal (schedule:run) terakhir. */
    private static function scheduler(): array
    {
        $last = Cache::get('health:scheduler_heartbeat') ?? now()->subMinutes(1)->toDateTimeString();
        $mins = (int) floor(now()->diffInMinutes($last, true));

        return self::check('scheduler', 'Penjadwal tugas', $mins > 10 ? 'gangguan' : 'ok',
            $mins > 10 ? "Tidak berjalan sejak {$mins} menit lalu — periksa cron server." : 'Berjalan tiap menit (retensi, pengingat hutang, cadangan).',
            ['last_run' => $last]);
    }

    /** Tiruan: cadangan terakhir. */
    private static function backup(): array
    {
        $last = now()->subHours(20)->setMinute(0)->toDateTimeString();

        return self::check('backup', 'Cadangan data', 'ok', 'Cadangan terakhir '.\Illuminate\Support\Carbon::parse($last)->diffForHumans().', 2,4 MB.',
            ['last_at' => $last, 'size' => 2_516_582, 'mock' => true]);
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

    /** Tiruan: gangguan terbaru (backend pemantauan menyusul). */
    private static function incidents(): array
    {
        return [
            ['at' => now()->subDays(2)->setTime(7, 12)->toDateTimeString(), 'check' => 'queue', 'status' => 'peringatan', 'message' => '3 tugas ekspor gagal, berhasil diulang otomatis.', 'resolved_at' => now()->subDays(2)->setTime(7, 20)->toDateTimeString()],
            ['at' => now()->subDays(6)->setTime(13, 40)->toDateTimeString(), 'check' => 'storage', 'status' => 'peringatan', 'message' => 'Sisa penyimpanan 18%. Berkas ekspor lama dibersihkan.', 'resolved_at' => now()->subDays(6)->setTime(14, 5)->toDateTimeString()],
        ];
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
