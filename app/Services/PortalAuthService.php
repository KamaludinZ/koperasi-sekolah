<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Masuk portal anggota (guard `member`): kode anggota atau email + kata sandi (PIN khusus verifikasi transaksi).
 * Pesan gagal selalu sama (tidak membocorkan apakah akun ada), 5 percobaan gagal per 15 menit
 * per identitas+IP, dan setiap masuk/gagal dicatat di jejak audit.
 */
class PortalAuthService
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    /** @throws ValidationException */
    public function attempt(string $identifier, string $password, string $ip): Member
    {
        $id = mb_strtolower(trim($identifier));
        $key = 'portal-login:'.sha1($id).'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['identifier' => "Terlalu banyak percobaan. Coba lagi dalam {$minutes} menit."]);
        }

        $member = Member::query()
            ->where(fn ($q) => $q->whereRaw('LOWER(member_code) = ?', [$id])->orWhereRaw('LOWER(email) = ?', [$id]))
            ->first();
        $hash = $member?->getAttributes()['password'] ?? null;

        if (! $member || ! $hash || ! Hash::check($password, $hash) || ! $member->hasPortalAccess()) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            AuditLog::record('portal.failed', 'auth', 'Gagal masuk portal anggota dengan "'.mb_substr(trim($identifier), 0, 60).'"',
                $member ? self::subject($member) : null, ['attempt' => RateLimiter::attempts($key)], actor: self::actor($member));
            throw ValidationException::withMessages(['identifier' => 'Kode/email atau kata sandi salah, atau akses portal belum aktif.']);
        }

        RateLimiter::clear($key);
        Auth::guard('member')->login($member);
        $member->forceFill(['portal_last_login_at' => now()])->saveQuietly();
        AuditLog::record('portal.login', 'auth', "{$member->name} masuk portal anggota", self::subject($member), actor: self::actor($member));

        return $member;
    }

    /**
     * Jalur masuk portal yang sama (guard `member`) untuk impersonasi oleh admin: tanpa kata sandi/PIN anggota, tanpa
     * mengubah portal_last_login_at, dan tanpa catatan portal.login (dicatat sebagai impersonasi oleh layanan
     * impersonasi). Sisa token PIN di sesi dibuang agar tak terpakai lintas akun.
     */
    public function enterAs(Member $member): void
    {
        session()->forget('portal.pin_token');
        Auth::guard('member')->login($member);
    }

    public function logout(): void
    {
        if ($member = Auth::guard('member')->user()) {
            AuditLog::record('portal.logout', 'auth', "{$member->name} keluar dari portal anggota", self::subject($member), actor: self::actor($member));
        }
        Auth::guard('member')->logout();
    }

    public static function subject(Member $m): array
    {
        return ['type' => 'member', 'id' => $m->id, 'label' => "{$m->member_code} · {$m->name}"];
    }

    /** Pelaku audit = anggota (bukan petugas). */
    public static function actor(?Member $m): ?array
    {
        return $m ? ['id' => null, 'name' => $m->name, 'role' => 'anggota'] : ['id' => null, 'name' => 'Tamu portal', 'role' => null];
    }
}
