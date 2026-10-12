<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Masuk & keluar petugas: cocokkan email + kata sandi (hash), tolak akun nonaktif
 * atau tanpa peran, batasi 5 percobaan gagal per email+IP selama 60 detik,
 * catat waktu login terakhir, dan tulis jejak audit untuk setiap hasil.
 */
class AuthService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 60;

    /**
     * @throws ValidationException (kunci `email`) bila gagal
     */
    public function login(string $email, string $password, bool $remember, Request $request): User
    {
        $email = mb_strtolower(trim($email));
        $key = self::throttleKey($email, $request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $wait = RateLimiter::availableIn($key);
            AuditLog::record('auth.locked', 'auth', "Percobaan masuk saat terkunci untuk {$email}", null, ['email' => $email], null, ['name' => $email]);

            throw ValidationException::withMessages(['email' => "Terlalu banyak percobaan. Coba lagi dalam {$wait} detik."]);
        }

        $user = User::with('role')->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            $attempts = RateLimiter::attempts($key);
            AuditLog::record('auth.failed', 'auth', "Gagal masuk: kata sandi salah atau akun tidak dikenal ({$email})", null,
                ['email' => $email, 'attempt' => $attempts, 'locked' => $attempts >= self::MAX_ATTEMPTS],
                null, $user ? self::actor($user) : ['name' => $email]);

            throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        if (! $user->is_active || ! $user->role) {
            AuditLog::record('auth.inactive', 'auth', "Akun nonaktif {$user->email} mencoba masuk", null, ['email' => $user->email], null, self::actor($user));

            throw ValidationException::withMessages(['email' => 'Akun ini dinonaktifkan. Hubungi admin koperasi.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, $remember);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditLog::record('auth.login', 'auth', "{$user->name} masuk sebagai {$user->role->label}", null, ['remember' => $remember]);

        return $user;
    }

    public function logout(Request $request): void
    {
        if ($user = Auth::user()) {
            AuditLog::record('auth.logout', 'auth', "{$user->name} keluar");
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->forget('mock.user');
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    /** Bentuk ringkas petugas untuk tampilan & audit. */
    public static function toArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'role_label' => $user->role?->label,
            'permissions' => $user->role?->permissions ?? [],
            'is_active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
        ];
    }

    public static function throttleKey(string $email, Request $request): string
    {
        return 'login:'.mb_strtolower(trim($email)).'|'.$request->ip();
    }

    private static function actor(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->name];
    }
}
