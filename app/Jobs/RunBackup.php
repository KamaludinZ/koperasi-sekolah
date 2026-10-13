<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Kerjakan satu cadangan (status berjalan) di antrean `cadangan`. */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $backupId)
    {
        $this->onQueue(config('koperasi.queues.cadangan', 'cadangan'));
    }

    public function handle(BackupService $backups): void
    {
        if ($b = Backup::find($this->backupId)) {
            $backups->execute($b);
        }
    }

    public function failed(?Throwable $e): void
    {
        Backup::whereKey($this->backupId)->where('status', 'berjalan')->update(['status' => 'gagal', 'error' => $e?->getMessage() ?? 'Gagal', 'finished_at' => now()]);
    }
}
