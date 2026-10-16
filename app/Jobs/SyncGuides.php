<?php

namespace App\Jobs;

use App\Models\GuideSyncJob;
use App\Services\GuideSyncInProgress;
use App\Services\GuideSyncRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sinkronisasi Pusat Panduan di antrean `default`: menyusun ulang panduan otomatis dari menu/halaman/izin terkini lewat
 * GuideSyncRecorder (tercatat di guide_sync_jobs, satu proses sekaligus). Dipicu penjadwal (harian) atau pemeriksa
 * perubahan (menu/izin berubah). Bila sinkronisasi lain sedang berjalan, dicoba lagi 1 menit kemudian. Unik per pemicu.
 */
class SyncGuides implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 900;

    public function __construct(public string $trigger = 'scheduled', public ?int $syncJobId = null, public ?string $note = null)
    {
        $this->onQueue(config('koperasi.queues.default', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->trigger;
    }

    /** Antre sinkronisasi dan catat sebagai "mengantre" di riwayat agar terlihat di panel. */
    public static function enqueue(string $trigger, ?string $note = null): GuideSyncJob
    {
        $job = GuideSyncJob::create(['status' => 'queued', 'trigger' => $trigger, 'note' => $note]);
        dispatch(new self($trigger, $job->id, $note));

        return $job->refresh();
    }

    public function handle(GuideSyncRecorder $recorder): void
    {
        $record = $this->syncJobId ? GuideSyncJob::find($this->syncJobId) : null;
        if ($record && $record->status !== 'queued') {
            return; // sudah diproses (kiriman ganda dari antrean)
        }
        try {
            $recorder->run($this->trigger, null, $this->note ?? 'Sinkronisasi otomatis ('.(GuideSyncJob::TRIGGERS[$this->trigger] ?? $this->trigger).')', null, $record);
        } catch (GuideSyncInProgress) {
            $record?->update(['status' => 'queued', 'started_at' => null]);
            $this->release(60);
        }
    }

    /** Gagal setelah semua percobaan: catatan riwayat ditandai gagal. */
    public function failed(?\Throwable $e): void
    {
        GuideSyncJob::whereKey($this->syncJobId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e ? class_basename($e).': '.$e->getMessage() : 'Gagal', 0, 500)]);
    }
}
