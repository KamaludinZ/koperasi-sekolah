<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Masuk & keluar petugas: cocokkan email + kata sandi (hash), tolak akun nonaktif
 * atau tanpa peran, kunci bertingkat setelah gagal berulang (LoginLockoutService),
 * catat waktu login terakhir, dan tulis jejak audit untuk setiap hasil.
 */
class AuthService
{
    /** Bawaan gagal beruntun sebelum kunci tingkat 1 (diatur di Keamanan & retensi). */
    public const MAX_ATTEMPTS = 5;

    /** Lama kunci tingkat 1 dalam detik. */
    public const LOCK_SECONDS = 60;

    public function __construct(private LoginLockoutService $lockouts) {}

    /**
     * @throws ValidationException (kunci `email`) bila gagal
     */
    public function login(string $email, string $password, bool $remember, Request $request): User
    {
        $email = LoginLockoutService::normalize($email);

        $lock = $this->lockouts->find($email);
        if ($lock?->isLocked()) {
            AuditLog::record('auth.locked', 'auth', "Percobaan masuk saat terkunci untuk {$email}", null,
                ['email' => $email, 'level' => $lock->level], null, ['name' => $email]);

            throw self::lockedException($lock->secondsLeft());
        }

        $user = User::with('role')->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $lock = $this->lockouts->fail($email, $request->ip(), $user);
            AuditLog::record('auth.failed', 'auth', "Gagal masuk: kata sandi salah atau akun tidak dikenal ({$email})", null,
                ['email' => $email, 'attempt' => $lock->failed_count, 'locked' => $lock->isLocked(), 'level' => $lock->level],
                null, $user ? self::actor($user) : ['name' => $email]);

            throw $lock->isLocked() ? self::lockedException($lock->secondsLeft()) : ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']);
        }

        if (! $user->is_active || ! $user->role) {
            AuditLog::record('auth.inactive', 'auth', "Akun nonaktif {$user->email} mencoba masuk", null, ['email' => $user->email], null, self::actor($user));

            throw ValidationException::withMessages(['email' => 'Akun ini dinonaktifkan. Hubungi admin koperasi.']);
        }

        $this->lockouts->clear($email);
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

    /** Penolakan masuk karena terkunci: sementara (sisa detik) atau sampai dibuka Admin (null). HTTP 429. */
    public static function lockedException(?int $seconds): ValidationException
    {
        return ValidationException::withMessages(['email' => $seconds === null
            ? 'Akun dikunci karena terlalu banyak percobaan masuk. Hubungi admin koperasi.'
            : "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik."])->status(429);
    }

    private static function actor(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->name];
    }
}
