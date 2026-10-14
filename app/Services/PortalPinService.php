<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi PIN transaksi portal anggota (6 digit, tersandi). Salah 5 kali → transaksi dikunci 15 menit.
 * Setiap PIN salah dicatat di jejak audit.
 */
class PortalPinService
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    public const PATTERN = '/^\d{6}$/';

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
                ['attempt' => RateLimiter::attempts($key)], actor: PortalAuthService::actor($member));
            throw ValidationException::withMessages(['pin' => $left > 0 ? "PIN salah. Sisa {$left} percobaan." : 'PIN salah terlalu sering. Coba lagi dalam 15 menit.']);
        }
        RateLimiter::clear($key);
    }
}
