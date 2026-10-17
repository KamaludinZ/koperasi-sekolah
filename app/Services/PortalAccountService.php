<?php

namespace App\Services;

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Akun portal anggota dari sisi pengurus/admin. Pengurus hanya MEMBUKA akses dan MEMBANTU: aktifkan akses dengan
 * kata sandi sementara (dibuat acak bila tidak diisi, ditampilkan sekali), atur ulang kata sandi (sementara lagi),
 * atur ulang PIN (PIN lama dihapus — pengurus tidak pernah tahu PIN), ubah email, dan cabut akses. Anggota sendiri
 * yang membuat kata sandi & PIN miliknya saat masuk berikutnya (halaman Siapkan akun, lihat PortalCredentialService).
 * Nilai rahasia tidak pernah disimpan polos dan tidak ikut dicatat di audit.
 */
class PortalAccountService
{
    /**
     * Aktifkan akses portal (atau atur ulang kata sandinya bila sudah aktif) dengan kata sandi SEMENTARA: anggota
     * wajib menggantinya saat masuk. Aktivasi baru juga mengosongkan PIN agar anggota membuat PIN sendiri.
     *
     * @return array{password: ?string} kata sandi sementara (polos) untuk diserahkan ke anggota, sekali tampil
     *
     * @throws ValidationException
     */
    public function enable(Member $member, ?string $email = null, ?string $password = null, ?User $by = null): array
    {
        if ($email !== null) {
            $this->validateEmail($member, $email);
        }
        validator(['password' => $password], ['password' => ['nullable', 'string', 'min:8', 'max:100']],
            ['password.min' => 'Kata sandi minimal 8 karakter.'])->validate();
        if (! $member->is_active) {
            throw ValidationException::withMessages(['member' => 'Anggota nonaktif tidak bisa diberi akses portal.']);
        }

        $firstTime = ! $member->hasPortalAccess();
        $email = self::normalizeEmail($email);
        $newPassword = $password ?? self::randomPassword();
        $member->forceFill(['email' => $email ?? $member->email, 'password' => $newPassword, 'portal_password_changed_at' => null]
            + ($firstTime ? ['pin' => null, 'portal_pin_changed_at' => null] : []))->saveQuietly();
        AuditLog::record($firstTime ? 'portal.enabled' : 'portal.password_reset', 'anggota',
            $firstTime ? "Akses portal {$member->name} diaktifkan".($email ? " (email {$email})" : '') : "Kata sandi portal {$member->name} diatur ulang (sementara)",
            PortalAuthService::subject($member), ['email' => $member->email]);

        return ['password' => $password === null ? $newPassword : null];
    }

    /** Atur ulang PIN: PIN lama dihapus & kuncinya dilepas; anggota membuat PIN baru sendiri saat masuk berikutnya. */
    public function resetPin(Member $member): void
    {
        if (! $member->hasPortalAccess()) {
            throw ValidationException::withMessages(['member' => 'Aktifkan akses portal anggota terlebih dahulu.']);
        }
        $member->forceFill(['pin' => null, 'portal_pin_changed_at' => null])->saveQuietly();
        RateLimiter::clear('portal-pin:'.$member->id);
        AuditLog::record('portal.pin_reset', 'anggota', "PIN transaksi {$member->name} diatur ulang — anggota membuat PIN baru saat masuk",
            PortalAuthService::subject($member));
    }

    /** Ubah (atau hapus, bila kosong) email masuk portal anggota. @throws ValidationException */
    public function updateEmail(Member $member, ?string $email): void
    {
        $this->validateEmail($member, (string) $email);
        $old = $member->email;
        $new = self::normalizeEmail($email);
        if ($old === $new) {
            return;
        }
        $member->forceFill(['email' => $new])->saveQuietly();
        AuditLog::record('portal.email_changed', 'anggota', "Email portal {$member->name} diubah", PortalAuthService::subject($member),
            [], action: 'diubah', old: ['email' => $old ?? '—'], new: ['email' => $new ?? '—']);
    }

    /** Cabut akses portal: anggota langsung keluar pada permintaan berikutnya (middleware portal). */
    public function disable(Member $member): void
    {
        $member->forceFill(['password' => null, 'pin' => null, 'portal_password_changed_at' => null, 'portal_pin_changed_at' => null])->saveQuietly();
        AuditLog::record('portal.disabled', 'anggota', "Akses portal {$member->name} dicabut", PortalAuthService::subject($member));
    }

    private function validateEmail(Member $member, string $email): void
    {
        validator(['email' => self::normalizeEmail($email)], [
            'email' => ['nullable', 'email', 'max:120', Rule::unique('members', 'email')->ignore($member->id)],
        ], ['email.unique' => 'Email sudah dipakai anggota lain.', 'email.email' => 'Format email tidak valid.'])->validate();
    }

    private static function normalizeEmail(?string $email): ?string
    {
        return $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
    }

    public static function randomPassword(): string
    {
        // Tanpa karakter mirip (0/O, 1/l/I) agar mudah dibacakan.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        return collect(range(1, 10))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }

    /** PIN mudah ditebak: satu angka diulang (111111) atau berurutan naik/turun (123456, 987654). */
    public static function weakPin(string $pin): bool
    {
        return (bool) preg_match('/^(\d)\1{5}$/', $pin) || str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }
}
