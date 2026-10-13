<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\User;
use App\Services\RestoreTestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Jalankan uji pemulihan satu cadangan di antrean `cadangan`. */
class RunRestoreTest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $backupId, public ?int $userId = null)
    {
        $this->onQueue(config('koperasi.queues.cadangan', 'cadangan'));
    }

    public function handle(RestoreTestService $tests): void
    {
        if ($b = Backup::find($this->backupId)) {
            $tests->run($b, $this->userId ? User::find($this->userId) : null);
        }
    }
}
