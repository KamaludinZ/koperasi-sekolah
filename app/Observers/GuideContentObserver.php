<?php

namespace App\Observers;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Services\GuideVersionRecorder;

/**
 * Jaring pengaman riwayat versi: setiap kali isi panduan (judul/ringkasan/langkah/tips) atau suntingannya disimpan
 * atau dihapus lewat model, versi dicatat setelah transaksi selesai bila isi efektifnya belum tercatat.
 */
class GuideContentObserver
{
    private const CONTENT = ['title', 'summary', 'steps', 'tips'];

    public function saved(GuideEntry|GuideOverride $model): void
    {
        if ($model instanceof GuideEntry && ! $model->wasRecentlyCreated && ! $model->wasChanged(self::CONTENT)) {
            return; // mis. hanya penanda usang/posisi yang berubah
        }
        $this->queue($model, 'Isi panduan disimpan');
    }

    public function deleted(GuideEntry|GuideOverride $model): void
    {
        if ($model instanceof GuideOverride) {
            $this->queue($model, 'Suntingan panduan dihapus');
        }
    }

    private function queue(GuideEntry|GuideOverride $model, string $note): void
    {
        app(GuideVersionRecorder::class)->recordAfterCommit($model instanceof GuideEntry ? $model->id : $model->guide_entry_id, $note);
    }
}
