<?php

namespace App\Models;

use App\Services\AuditLog;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Baris pivot user_roles (peran yang dipegang akun). Jaring pengaman jejak audit: setiap peran yang ditambah/dicabut
 * lewat relasi User::roles() di LUAR alur resmi UserService (yang mencatat user.role_changed sendiri) dicatat sebagai
 * `user.role_added` / `user.role_removed` — pelaku, akun target, peran, waktu — di activity_logs ber-HMAC.
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

    protected static function booted(): void
    {
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
        AuditLog::record("user.role_{$kind}", 'pengguna',
            'Peran '.$role->label.($kind === 'added' ? ' ditambahkan ke ' : ' dicabut dari ').$user->name.' (di luar halaman Pengguna)',
            ['type' => 'user', 'id' => $user->id, 'label' => "{$user->name} · {$user->email}"],
            [$kind => [$role->name], 'otomatis' => true], action: 'diubah',
            old: $kind === 'removed' ? ['role' => $role->name] : null, new: $kind === 'added' ? ['role' => $role->name] : null);
    }
}
