<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Akun portal anggota dikelola pengurus/admin: aktifkan (email opsional, kata sandi & PIN dibuat acak
 * bila tidak diisi — ditampilkan sekali), atur ulang kata sandi/PIN, dan cabut akses. Nilai rahasia
 * tidak pernah disimpan polos dan tidak ikut dicatat di audit.
 */
class PortalAccountService
{
    /**
     * @return array{password: ?string, pin: ?string} nilai baru (polos) untuk diserahkan ke anggota, sekali tampil
     *
     * @throws ValidationException
     */
    public function enable(Member $member, ?string $email = null, ?string $password = null, ?string $pin = null, ?User $by = null): array
    {
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        validator(['email' => $email, 'password' => $password, 'pin' => $pin], [
            'email' => ['nullable', 'email', 'max:120', Rule::unique('members', 'email')->ignore($member->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'regex:'.PortalPinService::PATTERN],
        ], [
            'email.unique' => 'Email sudah dipakai anggota lain.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'pin.regex' => 'PIN harus 6 angka.',
        ])->validate();
        if (! $member->is_active) {
            throw ValidationException::withMessages(['member' => 'Anggota nonaktif tidak bisa diberi akses portal.']);
        }

        $newPassword = $password ?? self::randomPassword();
        $newPin = $pin ?? self::randomPin();
        $member->forceFill(['email' => $email ?? $member->email, 'password' => $newPassword, 'pin' => $newPin])->saveQuietly();
        AuditLog::record('portal.enabled', 'anggota', "Akses portal {$member->name} diaktifkan/diatur ulang".($email ? " (email {$email})" : ''),
            PortalAuthService::subject($member), ['email' => $member->email]);

        return ['password' => $password === null ? $newPassword : null, 'pin' => $pin === null ? $newPin : null];
    }

    /** Atur ulang PIN saja; mengembalikan PIN baru (polos) bila dibuat acak. */
    public function resetPin(Member $member, ?string $pin = null): ?string
    {
        if ($pin !== null && ! preg_match(PortalPinService::PATTERN, $pin)) {
            throw ValidationException::withMessages(['pin' => 'PIN harus 6 angka.']);
        }
        if (! $member->hasPortalAccess()) {
            throw ValidationException::withMessages(['member' => 'Aktifkan akses portal anggota terlebih dahulu.']);
        }
        $new = $pin ?? self::randomPin();
        $member->forceFill(['pin' => $new])->saveQuietly();
        \Illuminate\Support\Facades\RateLimiter::clear('portal-pin:'.$member->id); // buka kunci PIN
        AuditLog::record('portal.pin_reset', 'anggota', "PIN transaksi {$member->name} diatur ulang", PortalAuthService::subject($member));

        return $pin === null ? $new : null;
    }

    /** Cabut akses portal: anggota langsung keluar pada permintaan berikutnya (middleware portal). */
    public function disable(Member $member): void
    {
        $member->forceFill(['password' => null, 'pin' => null])->saveQuietly();
        AuditLog::record('portal.disabled', 'anggota', "Akses portal {$member->name} dicabut", PortalAuthService::subject($member));
    }

    public static function randomPassword(): string
    {
        // Tanpa karakter mirip (0/O, 1/l/I) agar mudah dibacakan.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        return collect(range(1, 10))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }

    public static function randomPin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (preg_match('/^(\d)\1{5}$/', $pin) || in_array($pin, ['123456', '654321'], true)); // hindari PIN mudah ditebak

        return $pin;
    }
}
