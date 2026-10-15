<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Penanda catatan audit yang gagal verifikasi (lihat AuditVerificationService). */
class AuditIntegrityFailure extends Model
{
    public const KINDS = ['checksum' => 'Checksum HMAC tidak cocok', 'rantai' => 'Rantai impersonasi putus'];

    protected $fillable = ['kind', 'activity_log_id', 'impersonation_id', 'event', 'detail', 'first_detected_at', 'last_detected_at', 'cleared_at'];

    protected function casts(): array
    {
        return ['first_detected_at' => 'datetime', 'last_detected_at' => 'datetime', 'cleared_at' => 'datetime'];
    }

    /** Kegagalan yang masih berlaku (belum pulih). */
    public function scopeOpen(Builder $q): void
    {
        $q->whereNull('cleared_at');
    }
}
