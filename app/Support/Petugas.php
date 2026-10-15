<?php

namespace App\Support;

use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;

/**
 * Petugas yang sedang masuk dalam bentuk array ringkas
 * (id, name, email, role = peran utama, roles = semua peran, role_label, permissions = gabungan izin semua peran,
 * is_active, last_login_at), atau null.
 */
class Petugas
{
    public static function current(): ?array
    {
        $user = Auth::guard('web')->user(); // anggota portal (guard `member`) bukan petugas

        return $user ? AuthService::toArray($user->loadMissing('role', 'roles')) : null;
    }
}
