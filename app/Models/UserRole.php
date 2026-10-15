<?php

namespace App\Models;

use App\Services\AuditLog;
use App\Services\RoleAuditService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;

/**
 * Baris pivot user_roles (peran yang dipegang akun). Jaring pengaman jejak audit: setiap peran yang ditambah/dicabut
 * lewat relasi User::roles() di LUAR alur resmi UserService (yang mencatat user.role_changed sendiri) dicatat sebagai
 * `user.role_added` / `user.role_removed` — pelaku, akun target, peran, waktu — di activity_logs ber-HMAC.
 * Kolom: user_id, role_id (kunci gabungan), granted_by (pemberi, null bila konsol/akun pemberi dihapus), created_at.
 */
class UserRole extends Pivot
{
    protected $table = 'user_roles';

    public $incrementing = false;

    /** Hanya created_at — tabel tidak punya updated_at (pivot bawaan meminjam nama kolom dari User). */
    public function getUpdatedAtColumn()
    {
        return null;
    }

    /** Kedalaman alur resmi yang sedang berjalan (catatan per baris dilewati; alur resmi mencatat ringkasannya). */
    private static int $official = 0;

    public static function official(callable $fn): mixed
    {
        self::$official++;
        try {
            return $fn();
        } finally {
            self::$official--;
        }
    }

    /** Petugas yang sedang masuk (pemberi peran); null untuk konsol/seeder. */
    public static function currentGranter(): ?int
    {
        return Auth::guard('web')->id();
    }

    /** Petugas yang memasang peran ini (user_roles.granted_by). */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            $p->granted_by ??= self::currentGranter();
            $p->created_at ??= now();
        });
        static::created(fn (self $p) => $p->log('added'));
        static::deleted(fn (self $p) => $p->log('removed'));
    }

    private function log(string $kind): void
    {
        if (self::$official > 0 || AuditLog::isQuiet()) {
            return;
        }
        $user = User::find($this->user_id);
        $role = Role::find($this->role_id);
        if (! $user || ! $role) {
            return;
        }
        app(RoleAuditService::class)->recordPivotChange($kind, $user, $role);
    }
}
