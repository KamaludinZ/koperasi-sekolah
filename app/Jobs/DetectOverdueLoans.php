<?php

namespace App\Jobs;

use App\Services\LoanOverdueDetector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deteksi angsuran pinjaman yang lewat jatuh tempo di antrean `notifikasi` (terjadwal tiap hari 00:05): status
 * angsuran & pinjaman disegarkan, pinjaman yang baru menunggak masuk jejak audit & notifikasi pengurus.
 * Unik — tidak ada dua deteksi berjalan bersamaan.
 */
class DetectOverdueLoans implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue(config('koperasi.queues.notifikasi', 'notifikasi'));
    }

    public function handle(LoanOverdueDetector $detector): void
    {
        $detector->run();
    }
}
