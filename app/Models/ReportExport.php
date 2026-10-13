<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;

/**
 * Satu permintaan ekspor laporan yang dikerjakan di antrean latar. Berkas hasil disimpan di
 * disk privat `local` dan hanya bisa diunduh peminta. Dibersihkan otomatis beserta berkasnya
 * setelah `security.export_days` hari (pengaturan Keamanan & retensi; bawaan KEEP_DAYS),
 * lewat `retensi:bersihkan` di jadwal.
 */
class ReportExport extends Model
{
    use Prunable;

    public const KEEP_DAYS = 7;

    public const STATUSES = ['pending' => 'Menunggu antrean', 'processing' => 'Sedang dibuat', 'done' => 'Siap diunduh', 'failed' => 'Gagal'];

    protected $fillable = ['user_id', 'type', 'format', 'params', 'status', 'file_path', 'file_name', 'rows', 'error', 'finished_at'];

    protected function casts(): array
    {
        return ['params' => 'array', 'rows' => 'integer', 'finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::keepDays()));
    }

    public static function keepDays(): int
    {
        return (int) (app(SettingsService::class)->security()['export_days'] ?? self::KEEP_DAYS);
    }

    protected function pruning(): void
    {
        if ($this->file_path) {
            Storage::disk('local')->delete($this->file_path);
        }
    }
}
