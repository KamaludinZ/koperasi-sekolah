<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Anggota mengganti sendiri kata sandi & PIN portal (halaman Akun saya). Keduanya wajib dikonfirmasi dengan kata
 * sandi yang sekarang — sehingga PIN yang terlupa tetap bisa diganti sendiri, tetapi sesi yang tertinggal terbuka
 * tidak cukup untuk mengambil alih akun. Salah kata sandi 5 kali → penggantian dikunci 15 menit. Kata sandi baru
 * min. 8 karakter berisi huruf & angka dan berbeda dari yang lama; PIN 6 angka yang tidak mudah ditebak. Setelah
 * kata sandi diganti, sesi portal di perangkat lain berakhir (sidik kata sandi, lihat EnsurePortalMember). Semua
 * kejadian dicatat di audit tanpa nilai rahasianya.
 */
class PortalCredentialService
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 900;

    /** @throws ValidationException */
    public function changePassword(Member $member, array $input): void
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/\d/', 'different:current_password', 'confirmed'],
        ], [
            'current_password.required' => 'Masukkan kata sandi yang sekarang.',
            'password.required' => 'Masukkan kata sandi baru.',
            'password.min' => 'Minimal 8 karakter.',
            'password.max' => 'Maksimal 100 karakter.',
            'password.regex' => 'Gunakan gabungan huruf dan angka.',
            'password.different' => 'Kata sandi baru harus berbeda dari yang sekarang.',
            'password.confirmed' => 'Ulangan kata sandi baru tidak sama.',
        ])->validate();
        $this->confirm($member, $data['current_password'], 'kata sandi');

        $member->forceFill(['password' => $data['password'], 'portal_password_changed_at' => now()])->saveQuietly();
        PortalAuthService::rememberCredential($member); // perangkat ini tetap masuk; perangkat lain keluar
        AuditLog::record('portal.password_changed', 'auth', "{$member->name} mengganti kata sandi portalnya sendiri",
            PortalAuthService::subject($member), actor: PortalAuthService::actor($member));
    }

    /** @throws ValidationException */
    public function changePin(Member $member, array $input): void
    {
        $data = Validator::make($input, [
            'pin_password' => ['required', 'string', 'max:100'],
            'pin' => ['required', 'regex:'.PortalPinService::PATTERN, 'confirmed'],
        ], [
            'pin_password.required' => 'Masukkan kata sandi portal untuk konfirmasi.',
            'pin.required' => 'Masukkan PIN baru.',
            'pin.regex' => 'PIN harus 6 angka.',
            'pin.confirmed' => 'Ulangan PIN baru tidak sama.',
        ])->validate();
        if (PortalAccountService::weakPin($data['pin'])) {
            throw ValidationException::withMessages(['pin' => 'PIN terlalu mudah ditebak (angka sama atau berurutan). Pilih PIN lain.']);
        }
        $this->confirm($member, $data['pin_password'], 'PIN', 'pin_password');
        if ($member->hasPin() && Hash::check($data['pin'], $member->getAttributes()['pin'])) {
            throw ValidationException::withMessages(['pin' => 'PIN baru harus berbeda dari PIN yang sekarang.']);
        }

        $member->forceFill(['pin' => $data['pin'], 'portal_pin_changed_at' => now()])->saveQuietly();
        RateLimiter::clear('portal-pin:'.$member->id); // PIN baru: kunci karena PIN lama salah dilepas
        AuditLog::record('portal.pin_changed', 'auth', "{$member->name} mengganti PIN transaksi portalnya sendiri",
            PortalAuthService::subject($member), actor: PortalAuthService::actor($member));
    }

    /**
     * Siapkan akun saat pertama masuk (atau setelah pengurus mengatur ulang): ganti kata sandi sementara dengan milik
     * sendiri dan/atau buat PIN transaksi. Hanya bagian yang memang perlu yang diminta; anggota baru saja masuk dengan
     * kata sandi sementaranya sehingga tidak perlu mengetiknya lagi, tetapi kata sandi baru tidak boleh sama.
     *
     * @throws ValidationException
     */
    public function completeSetup(Member $member, array $input): void
    {
        $needPassword = $member->portal_password_changed_at === null;
        $needPin = ! $member->hasPin();
        if (! $needPassword && ! $needPin) {
            return;
        }
        $data = Validator::make($input, array_merge(
            $needPassword ? ['password' => ['required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/\d/', 'confirmed']] : [],
            $needPin ? ['pin' => ['required', 'regex:'.PortalPinService::PATTERN, 'confirmed']] : [],
        ), [
            'password.required' => 'Buat kata sandi baru.',
            'password.min' => 'Minimal 8 karakter.',
            'password.max' => 'Maksimal 100 karakter.',
            'password.regex' => 'Gunakan gabungan huruf dan angka.',
            'password.confirmed' => 'Ulangan kata sandi tidak sama.',
            'pin.required' => 'Buat PIN 6 angka.',
            'pin.regex' => 'PIN harus 6 angka.',
            'pin.confirmed' => 'Ulangan PIN tidak sama.',
        ])->validate();
        if ($needPassword && Hash::check($data['password'], (string) ($member->getAttributes()['password'] ?? ''))) {
            throw ValidationException::withMessages(['password' => 'Kata sandi baru harus berbeda dari kata sandi sementara dari pengurus.']);
        }
        if ($needPin && PortalAccountService::weakPin($data['pin'])) {
            throw ValidationException::withMessages(['pin' => 'PIN terlalu mudah ditebak (angka sama atau berurutan). Pilih PIN lain.']);
        }

        $member->forceFill(($needPassword ? ['password' => $data['password'], 'portal_password_changed_at' => now()] : [])
            + ($needPin ? ['pin' => $data['pin'], 'portal_pin_changed_at' => now()] : []))->saveQuietly();
        if ($needPassword) {
            PortalAuthService::rememberCredential($member);
        }
        if ($needPin) {
            RateLimiter::clear('portal-pin:'.$member->id);
        }
        $what = implode(' & ', array_filter([$needPassword ? 'kata sandi' : null, $needPin ? 'PIN transaksi' : null]));
        AuditLog::record('portal.setup_completed', 'auth', "{$member->name} membuat {$what} portalnya sendiri",
            PortalAuthService::subject($member), ['password' => $needPassword, 'pin' => $needPin], actor: PortalAuthService::actor($member));
    }

    /** @return array{locked: bool, minutes: int} */
    public function lockStatus(Member $member): array
    {
        $key = self::key($member);
        $locked = RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS);

        return ['locked' => $locked, 'minutes' => $locked ? (int) ceil(RateLimiter::availableIn($key) / 60) : 0];
    }

    private function confirm(Member $member, string $password, string $what, string $field = 'current_password'): void
    {
        $key = self::key($member);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([$field => "Terlalu banyak kata sandi salah. Coba lagi dalam {$minutes} menit."]);
        }
        $hash = $member->getAttributes()['password'] ?? null;
        if (! $hash || ! Hash::check($password, $hash)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $left = max(0, self::MAX_ATTEMPTS - RateLimiter::attempts($key));
            AuditLog::record('portal.credential_change_failed', 'auth', "{$member->name} gagal mengganti {$what} portal (kata sandi salah)",
                PortalAuthService::subject($member), ['left' => $left], actor: PortalAuthService::actor($member));

            throw ValidationException::withMessages([$field => $left > 0 ? "Kata sandi salah. Sisa {$left} percobaan." : 'Kata sandi salah terlalu sering. Coba lagi dalam 15 menit.']);
        }
        RateLimiter::clear($key);
    }

    private static function key(Member $member): string
    {
        return 'portal-credential:'.$member->id;
    }
}
