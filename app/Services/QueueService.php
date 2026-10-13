<?php

namespace App\Services;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status antrean pekerjaan latar untuk Sistem → Antrean & API: pekerja (denyut dari queue:work), tugas
 * menunggu (tabel jobs), tugas gagal (failed_jobs) dengan baris pertama galat, jumlah selesai hari ini,
 * dan 20 pekerjaan selesai terakhir (dicatat lewat event Queue::before/after). Ulangi/hapus tugas gagal
 * memakai perintah queue:retry / queue:forget.
 */
class QueueService
{
    public const LABELS = [
        \App\Jobs\ExportCashRecap::class => 'Ekspor rekap kas',
        \App\Jobs\ExportSalesReport::class => 'Ekspor laporan penjualan',
        \App\Jobs\SyncStockAlerts::class => 'Peringatan stok',
        \App\Jobs\RunBackup::class => 'Cadangan data',
        \App\Jobs\RunRestoreTest::class => 'Uji pemulihan',
    ];

    public const RECENT_KEY = 'queue:recent';

    /** @var array<string, float> waktu mulai per id job (dalam proses pekerja) */
    private static array $started = [];

    public static function label(?string $class): string
    {
        return self::LABELS[$class] ?? class_basename((string) $class);
    }

    public static function jobStarted(Job $job): void
    {
        self::$started[$job->getJobId() ?? spl_object_hash($job)] = microtime(true);
    }

    /** Catat pekerjaan selesai: penghitung harian & daftar 20 terakhir. */
    public static function jobFinished(Job $job): void
    {
        $id = $job->getJobId() ?? spl_object_hash($job);
        $duration = isset(self::$started[$id]) ? round(microtime(true) - self::$started[$id], 1) : null;
        unset(self::$started[$id]);
        $day = now()->format('Ymd');
        Cache::add("queue:processed:{$day}", 0, now()->addDays(2));
        Cache::increment("queue:processed:{$day}");
        $recent = Cache::get(self::RECENT_KEY, []);
        array_unshift($recent, ['id' => (string) $id, 'name' => self::label($job->resolveName()), 'queue' => $job->getQueue(),
            'duration_s' => $duration, 'by' => null, 'finished_at' => now()->toDateTimeString()]);
        Cache::put(self::RECENT_KEY, array_slice($recent, 0, 20), now()->addDays(7));
    }

    public function worker(): array
    {
        $beat = Cache::get('health:worker_heartbeat');
        $sync = config('queue.default') === 'sync';

        return [
            'running' => $sync || ($beat && Carbon::parse($beat)->gte(now()->subMinutes(HealthCheckService::HEARTBEAT_MINUTES))),
            'sync' => $sync,
            'last_seen' => $beat,
            'processed_today' => (int) Cache::get('queue:processed:'.now()->format('Ymd'), 0),
            'command' => 'php artisan queue:work '.config('koperasi.queue_worker'),
        ];
    }

    /** @return list<array{id: int, name: string, queue: string, attempts: int, waiting_min: int, created_at: string}> */
    public function pending(int $limit = 100): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')->orderBy('id')->limit($limit)->get()->map(function ($j) {
            $payload = json_decode($j->payload, true) ?: [];

            return ['id' => $j->id, 'name' => self::label($payload['displayName'] ?? null), 'queue' => $j->queue, 'attempts' => (int) $j->attempts,
                'waiting_min' => (int) floor((time() - (int) $j->created_at) / 60), 'created_at' => Carbon::createFromTimestamp((int) $j->created_at)->toDateTimeString()];
        })->all();
    }

    /** @return list<array{uuid: string, name: string, queue: string, failed_at: string, error: string}> */
    public function failed(int $limit = 50): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')->orderByDesc('failed_at')->orderByDesc('id')->limit($limit)->get()->map(function ($f) {
            $payload = json_decode($f->payload, true) ?: [];

            return ['uuid' => $f->uuid, 'name' => self::label($payload['displayName'] ?? null), 'queue' => $f->queue,
                'failed_at' => Carbon::parse($f->failed_at)->toDateTimeString(), 'error' => mb_strimwidth(trim((string) strtok((string) $f->exception, "\n")), 0, 160, '…')];
        })->all();
    }

    public function recent(): array
    {
        return Cache::get(self::RECENT_KEY, []);
    }

    /** Masukkan lagi tugas gagal ke antrean ('all' = semua). Mengembalikan jumlah yang diulang. */
    public function retry(string $uuid): int
    {
        $count = $uuid === 'all' ? DB::table('failed_jobs')->count() : DB::table('failed_jobs')->where('uuid', $uuid)->count();
        if ($count) {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
            AuditLog::record('queue.retried', 'pengaturan', $uuid === 'all' ? "Mengulang {$count} tugas antrean gagal" : 'Mengulang tugas antrean gagal', null, ['uuid' => $uuid, 'count' => $count]);
        }

        return $count;
    }

    public function forget(string $uuid): bool
    {
        if (! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            return false;
        }
        Artisan::call('queue:forget', ['id' => $uuid]);
        AuditLog::record('queue.forgotten', 'pengaturan', 'Menghapus tugas antrean gagal dari daftar', null, ['uuid' => $uuid]);

        return true;
    }
}
