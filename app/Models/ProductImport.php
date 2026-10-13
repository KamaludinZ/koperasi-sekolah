<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu proses impor barang dari berkas (lihat ProductImportService). Status:
 * diunggah → pratinjau (baris terbaca) → diproses → selesai | sebagian; atau gagal (berkas tak terbaca).
 */
class ProductImport extends Model
{
    public const STATUSES = ['diunggah', 'pratinjau', 'diproses', 'selesai', 'sebagian', 'gagal'];

    protected $fillable = [
        'user_id', 'committed_by', 'warnings', 'file_name', 'format', 'file_path', 'file_size', 'status', 'mode', 'update_stock',
        'total_rows', 'created', 'updated', 'skipped', 'failed', 'file_error', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'update_stock' => 'boolean',
            'file_size' => 'integer',
            'total_rows' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'finished_at' => 'datetime',
            'warnings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Petugas yang menyimpan impor ke data barang. */
    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ProductImportRow::class)->orderBy('line');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['diunggah', 'pratinjau'], true);
    }
}
