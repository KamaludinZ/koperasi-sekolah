<?php

namespace App\Models\Concerns;

use App\Services\AuditLog;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Hapus lunak dengan jejak siapa & mengapa (`deleted_by`, `delete_reason`). Data terhapus
 * disimpan selama `security.trash_days` (pengaturan Keamanan & retensi) lalu boleh dibersihkan
 * permanen; selama itu bisa dipulihkan. Alasan ikut tercatat di audit (AuditLog::withReason).
 */
trait SoftDeletesWithReason
{
    use SoftDeletes;

    public static function bootSoftDeletesWithReason(): void
    {
        static::restoring(function ($model) {
            $model->forceFill(['deleted_by' => null, 'delete_reason' => null]);
        });
    }

    /** Hapus lunak dengan alasan & pelaku (bawaan: petugas yang masuk). */
    public function trash(?string $reason = null, ?int $by = null): bool
    {
        $reason = filled($reason) ? mb_substr(trim($reason), 0, 200) : null;

        return AuditLog::withReason($reason, function () use ($reason, $by) {
            $this->forceFill(['deleted_by' => $by ?? auth()->id(), 'delete_reason' => $reason])->saveQuietly();

            return (bool) $this->delete();
        });
    }

    /** Lama data terhapus disimpan sebelum boleh dibersihkan permanen (hari). */
    public static function retentionDays(): int
    {
        return (int) app(SettingsService::class)->security()['trash_days'];
    }

    /** Kapan data terhapus ini boleh dibersihkan permanen. */
    public function purgeAt(): ?Carbon
    {
        return $this->deleted_at?->copy()->addDays(static::retentionDays());
    }

    /** Data terhapus yang sudah melewati masa retensi. */
    public function scopeExpiredTrash(Builder $query): void
    {
        $query->onlyTrashed()->where($this->getQualifiedDeletedAtColumn(), '<=', now()->subDays(static::retentionDays()));
    }
}
