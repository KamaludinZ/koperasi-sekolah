<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu sesi impersonasi (tabel impersonation_sessions): admin pelaku, target petugas/anggota (+ salinan nama),
 * alasan, batas waktu, kapan & bagaimana berakhir. Lihat App\Services\ImpersonationService.
 */
class Impersonation extends Model
{
    use HasUuids;

    protected $table = 'impersonation_sessions';

    public const TARGET_TYPES = ['petugas' => 'Petugas', 'anggota' => 'Anggota portal'];

    /** Cara sesi berakhir. */
    public const END_REASONS = [
        'manual' => 'Kembali lewat tombol',
        'kedaluwarsa' => 'Waktu habis',
        'keluar' => 'Keluar',
        'dicabut' => 'Diakhiri karena akun/hak berubah',
        'sesi_berakhir' => 'Sesi peramban berakhir',
    ];

    protected $fillable = [
        'admin_id', 'admin_name', 'target_type', 'target_user_id', 'target_member_id', 'target_name', 'target_label',
        'reason', 'started_at', 'expires_at', 'ended_at', 'end_reason', 'ip_address', 'user_agent', 'start_log_id', 'end_log_id',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function targetMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'target_member_id')->withTrashed();
    }

    /** Sesi yang belum berakhir (bisa saja sudah lewat batas waktu tapi belum ditutup). */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /** Sesi yang masih berlaku: belum berakhir dan belum lewat batas waktu. */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /** Lama sesi berjalan (menit, dibulatkan ke atas) sampai berakhir atau sampai sekarang. */
    public function durationMinutes(): int
    {
        return (int) ceil($this->started_at->diffInSeconds($this->ended_at ?? now()) / 60);
    }

    public function startLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class, 'start_log_id');
    }

    public function endLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class, 'end_log_id');
    }

    /** Catatan activity_logs yang dibuat selama sesi ini (aksi admin atas nama target). */
    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'impersonation_id')->whereNotIn('event', ['impersonation.started', 'impersonation.ended']);
    }

    public function target(): User|Member|null
    {
        return $this->target_type === 'anggota' ? $this->targetMember : $this->targetUser;
    }
}
