<?php

namespace App\Services;

use App\Models\GuideSyncJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Menjalankan penyusunan ulang panduan (GuideGenerator::sync) sambil mencatat prosesnya di guide_sync_jobs:
 * running → done (dengan hitungan hasil) atau failed (pesan galat dipotong; galat tetap dilempar ulang).
 * Penyusunan ulang menyeluruh juga menandai panduan usang dari perubahan menu/izin (GuideStaleMarker) lalu memperbarui
 * garis dasar metadata menu & izin (guide_menu_snapshots).
 * Hanya satu penyusunan ulang berjalan pada satu waktu (kunci cache); bila kunci dipegang proses lain → GuideSyncInProgress.
 */
class GuideSyncRecorder
{
    public const LOCK = 'panduan:sinkronisasi';

    public const LOCK_SECONDS = 300;

    public function __construct(private GuideGenerator $generator) {}

    /**
     * @param  ?list<string>  $only  batasi ke kunci panduan tertentu
     * @return array{job: GuideSyncJob, stats: array<string, list<string>>}
     */
    public function run(string $trigger, ?User $by = null, ?string $note = null, ?array $only = null, ?GuideSyncJob $job = null): array
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new GuideSyncInProgress;
        }
        try {
            return $this->runLocked($trigger, $by, $note, $only, $job);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(string $trigger, ?User $by, ?string $note, ?array $only, ?GuideSyncJob $job): array
    {
        $job ??= GuideSyncJob::create(['status' => 'queued', 'trigger' => $trigger, 'triggered_by' => $by?->id, 'scope' => $only, 'note' => $note]);
        $job->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        $meta = app(GuideMetadataService::class);
        try {
            $diff = $only === null ? $meta->diff() : null; // perubahan menu/izin sejak garis dasar, sebelum disusun ulang
            $stats = $this->generator->sync($by, $note ?? 'Disusun otomatis dari konfigurasi menu', $only, $job);
            if ($diff !== null) {
                $stats['stale'] = app(GuideStaleMarker::class)->mark($diff); // yang tak bisa diperbaiki penyusunan ulang
                $meta->capture(); // panduan sudah sesuai menu/izin saat ini → jadikan garis dasar baru
            }
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)]);
            throw $e;
        }
        $job->update(['status' => 'done', 'finished_at' => now(), 'stats' => array_map('count', $stats)]);
        $this->audit($job, $stats, $by, $note);

        return ['job' => $job, 'stats' => $stats];
    }

    /**
     * Satu jejak audit ber-HMAC per sinkronisasi yang mengubah sesuatu (panduan dibuat/diperbarui, draf disusun, ditandai
     * usang): pemicu, proses, cakupan, dan kunci panduan terdampak. Sinkronisasi tanpa perubahan tidak dicatat.
     */
    private function audit(GuideSyncJob $job, array $stats, ?User $by, ?string $note): void
    {
        $touched = array_filter(array_intersect_key($stats, array_flip(['created', 'updated', 'drafted', 'stale'])));
        if ($touched === []) {
            return;
        }
        $c = array_map('count', $stats);
        $parts = array_filter(['created' => "{$c['created']} baru", 'updated' => "{$c['updated']} diperbarui", 'drafted' => ($c['drafted'] ?? 0).' draf', 'stale' => ($c['stale'] ?? 0).' ditandai usang'],
            fn ($k) => ($c[$k] ?? 0) > 0, ARRAY_FILTER_USE_KEY);
        AuditLog::record('guide.synced', 'panduan',
            'Panduan disusun ulang ('.(GuideSyncJob::TRIGGERS[$job->trigger] ?? $job->trigger).', #'.$job->id.'): '.implode(', ', $parts),
            ['type' => 'guide_sync', 'id' => $job->id, 'label' => '#'.$job->id],
            ['sync_job_id' => $job->id, 'trigger' => $job->trigger, 'scope' => $job->scope] + $touched + ['counts' => $c],
            actor: $by ? ['id' => $by->id, 'name' => $by->name, 'role' => $by->role?->name] : null,
            action: 'diubah', reason: $note);
    }
}
