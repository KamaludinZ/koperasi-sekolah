<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu arsip cadangan data (basis data ± berkas) beserta status & hasilnya. */
class Backup extends Model
{
    use HasFactory;

    public const TYPES = ['lengkap' => 'Basis data + berkas', 'basis data' => 'Basis data saja'];

    public const TRIGGERS = ['terjadwal' => 'Terjadwal', 'manual' => 'Manual'];

    public const STATUSES = ['berjalan' => 'Berjalan', 'berhasil' => 'Berhasil', 'gagal' => 'Gagal'];

    protected $fillable = ['name', 'disk', 'path', 'type', 'trigger', 'status', 'size', 'duration_s', 'checksum', 'includes', 'note', 'error', 'created_by', 'started_at', 'finished_at'];

    protected $attributes = ['status' => 'berjalan', 'disk' => 'local', 'size' => 0, 'duration_s' => 0];

    protected function casts(): array
    {
        return ['includes' => 'array', 'size' => 'integer', 'duration_s' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', 'berhasil');
    }

    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /** Bentuk data untuk halaman (sama dengan MockData::backups()). */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->started_at->toDateTimeString(),
            'type' => $this->type,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'size' => $this->size,
            'duration_s' => $this->duration_s,
            'includes' => $this->includes ?? [],
            'note' => $this->status === 'gagal' ? ($this->error ? strtok($this->error, "\n") : $this->note) : $this->note,
            'by' => $this->creator?->name,
            'checksum' => $this->checksum ? substr($this->checksum, 0, 16) : null,
        ];
    }
}
