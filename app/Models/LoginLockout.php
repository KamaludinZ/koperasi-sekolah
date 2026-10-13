<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Status kunci login satu email (lihat LoginLockoutService). `level` 3 tanpa `locked_until`
 * berarti terkunci sampai dibuka Admin.
 */
class LoginLockout extends Model
{
    protected $fillable = ['email', 'user_id', 'failed_count', 'level', 'locked_at', 'locked_until', 'last_attempt_at', 'ip_addresses'];

    protected function casts(): array
    {
        return [
            'failed_count' => 'integer',
            'level' => 'integer',
            'locked_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_attempt_at' => 'datetime',
            'ip_addresses' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isManual(): bool
    {
        return $this->level >= 3 && $this->locked_until === null;
    }

    public function isLocked(): bool
    {
        return $this->level > 0 && ($this->isManual() || ($this->locked_until && $this->locked_until->isFuture()));
    }

    /** Detik tersisa sampai kunci sementara habis; null untuk kunci manual, 0 bila tidak terkunci. */
    public function secondsLeft(): ?int
    {
        if ($this->isManual()) {
            return null;
        }

        return $this->isLocked() ? max(1, (int) ceil(now()->diffInSeconds($this->locked_until, false))) : 0;
    }

    /** Email yang sedang terkunci (sementara yang belum habis, atau manual). */
    public function scopeLocked(Builder $query): void
    {
        $query->where('level', '>', 0)->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '>', now()));
    }
}
