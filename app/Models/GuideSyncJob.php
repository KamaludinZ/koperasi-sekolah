<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu proses sinkronisasi Pusat Panduan (tabel guide_sync_jobs). */
class GuideSyncJob extends Model
{
    public const STATUSES = ['queued' => 'Mengantre', 'running' => 'Berjalan', 'done' => 'Selesai', 'failed' => 'Gagal'];

    public const TRIGGERS = ['scheduled' => 'Terjadwal', 'menu_changed' => 'Menu berubah', 'permission_changed' => 'Izin peran berubah', 'manual' => 'Manual'];

    protected $fillable = ['status', 'trigger', 'triggered_by', 'scope', 'stats', 'note', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['scope' => 'array', 'stats' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /** Lama proses dalam detik (null bila belum selesai). */
    public function durationSeconds(): ?int
    {
        return $this->started_at && $this->finished_at ? max(0, (int) $this->started_at->diffInSeconds($this->finished_at)) : null;
    }
}
