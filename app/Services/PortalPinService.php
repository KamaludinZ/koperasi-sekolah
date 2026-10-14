<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi PIN transaksi portal anggota (6 digit, tersandi). Salah 3 kali → transaksi dikunci 15 menit.
 * Setiap percobaan dicatat di jejak audit: benar (pin_verified), salah (pin_failed), dan terkunci (pin_locked).
 */
class PortalPinService
{
    public const MAX_ATTEMPTS = 3;

    public const DECAY_SECONDS = 900;

    public const PATTERN = '/^\d{6}$/';

    /** @return array{left: int, locked: bool, minutes: int, seconds: int} sisa percobaan PIN anggota saat ini */
    public function attempts(Member $member): array
    {
        $key = 'portal-pin:'.$member->id;
        $locked = RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS);

        $seconds = $locked ? max(1, RateLimiter::availableIn($key)) : 0;

        return ['left' => max(0, self::MAX_ATTEMPTS - RateLimiter::attempts($key)), 'locked' => $locked,
            'minutes' => (int) ceil($seconds / 60), 'seconds' => $seconds];
    }

    public const TOKEN_TTL = 300;

    /** Token sekali pakai (5 menit) setelah PIN benar, disimpan di sesi anggota; dipakai endpoint transaksi. */
    public function issueToken(Member $member): array
    {
        $token = \Illuminate\Support\Str::random(40);
        $expires = now()->addSeconds(self::TOKEN_TTL);
        session(['portal.pin_token' => ['hash' => hash('sha256', $token), 'member_id' => $member->id, 'expires' => $expires->timestamp]]);

        return ['token' => $token, 'expires_at' => $expires->toIso8601String()];
    }

    /** Pakai token: sah bila milik anggota ini & belum kedaluwarsa; selalu dihapus setelah dicek (sekali pakai). */
    public function consumeToken(Member $member, ?string $token): bool
    {
        $stored = session()->pull('portal.pin_token');

        return $token !== null && $stored && $stored['member_id'] === $member->id && $stored['expires'] >= now()->timestamp
            && hash_equals($stored['hash'], hash('sha256', $token));
    }

    /** @throws ValidationException */
    public function verify(Member $member, string $pin): void
    {
        if (! $member->hasPin()) {
            throw ValidationException::withMessages(['pin' => 'PIN transaksi belum diatur. Minta pengurus koperasi mengaturnya.']);
        }
        $key = 'portal-pin:'.$member->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['pin' => "PIN salah terlalu sering. Coba lagi dalam {$minutes} menit."]);
        }
        if (! preg_match(self::PATTERN, $pin) || ! Hash::check($pin, $member->getAttributes()['pin'])) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $left = self::MAX_ATTEMPTS - RateLimiter::attempts($key);
            AuditLog::record('portal.pin_failed', 'auth', "PIN transaksi salah untuk {$member->name}", PortalAuthService::subject($member),
                ['attempt' => RateLimiter::attempts($key), 'left' => max(0, $left)], actor: PortalAuthService::actor($member));
            if ($left <= 0) {
                AuditLog::record('portal.pin_locked', 'auth', "Transaksi portal {$member->name} dikunci 15 menit karena PIN salah ".self::MAX_ATTEMPTS.' kali',
                    PortalAuthService::subject($member), ['minutes' => intdiv(self::DECAY_SECONDS, 60)], actor: PortalAuthService::actor($member));
            }
            throw ValidationException::withMessages(['pin' => $left > 0 ? "PIN salah. Sisa {$left} percobaan." : 'PIN salah terlalu sering. Coba lagi dalam 15 menit.']);
        }
        RateLimiter::clear($key);
        AuditLog::record('portal.pin_verified', 'auth', "PIN transaksi {$member->name} terverifikasi", PortalAuthService::subject($member),
            actor: PortalAuthService::actor($member));
    }
}
