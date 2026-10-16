<?php

namespace App\Services;

use App\Models\GuideSyncJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Menjalankan penyusunan ulang panduan (GuideGenerator::sync) sambil mencatat prosesnya di guide_sync_jobs:
 * running → done (dengan hitungan hasil) atau failed (pesan galat dipotong; galat tetap dilempar ulang).
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
        try {
            $stats = $this->generator->sync($by, $note ?? 'Disusun otomatis dari konfigurasi menu', $only, $job);
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)]);
            throw $e;
        }
        $job->update(['status' => 'done', 'finished_at' => now(), 'stats' => array_map('count', $stats)]);

        return ['job' => $job, 'stats' => $stats];
    }
}
