<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu uji pemulihan dari arsip cadangan ke basis data uji sementara. */
class RestoreTest extends Model
{
    protected $fillable = ['backup_id', 'backup_name', 'backup_at', 'status', 'steps', 'tables', 'mismatches', 'duration_s', 'error', 'run_by', 'started_at', 'finished_at'];

    protected $attributes = ['status' => 'berjalan', 'mismatches' => 0, 'duration_s' => 0];

    protected function casts(): array
    {
        return ['steps' => 'array', 'tables' => 'array', 'mismatches' => 'integer', 'duration_s' => 'integer', 'backup_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    /** Bentuk data riwayat untuk halaman (sama dengan MockData::restoreTests()). */
    public function toRow(): array
    {
        return [
            'id' => $this->id, 'backup' => $this->backup_name, 'backup_at' => $this->backup_at?->toDateTimeString(),
            'started_at' => $this->started_at->toDateTimeString(), 'duration_s' => $this->duration_s, 'status' => $this->status,
            'by' => $this->runner?->name ?? 'Sistem', 'tables' => count($this->tables ?? []), 'mismatches' => $this->mismatches,
            'note' => $this->status === 'gagal' ? strtok((string) $this->error, "\n") : null,
        ];
    }
}
