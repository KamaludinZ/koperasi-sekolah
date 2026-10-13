<?php

namespace App\Services;

use App\Models\LoginLockout;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kunci login bertingkat per email. Dengan N = `security.max_attempts` (bawaan 5):
 * N× gagal beruntun → kunci 1 menit (tingkat 1), 2N× → 15 menit (tingkat 2),
 * 3N× → terkunci sampai dibuka Admin (tingkat 3, Admin diberi notifikasi).
 * Hitungan kembali nol setelah berhasil masuk, dibuka Admin, atau 24 jam tanpa percobaan
 * (kecuali kunci tingkat 3). Semua kejadian tercatat di audit kategori auth.
 */
class LoginLockoutService
{
    /** Lama kunci per tingkat dalam menit; null = sampai dibuka Admin. */
    public const TIER_MINUTES = [1 => 1, 2 => 15, 3 => null];

    /** Hitungan gagal dilupakan setelah sekian jam tanpa percobaan (kecuali kunci manual). */
    public const RESET_AFTER_HOURS = 24;

    public static function maxAttempts(): int
    {
        return (int) (app(SettingsService::class)->security()['max_attempts'] ?? AuthService::MAX_ATTEMPTS);
    }

    /** @return list<array{level: int, failed: int, minutes: ?int, label: string}> */
    public static function tiers(): array
    {
        $n = self::maxAttempts();

        return collect(self::TIER_MINUTES)->map(fn (?int $m, int $level) => [
            'level' => $level,
            'failed' => $n * $level,
            'minutes' => $m,
            'label' => $m === null ? 'Sampai dibuka Admin' : $m.' menit',
        ])->values()->all();
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Baris status email (setelah hitungan basi dilupakan), atau null. */
    public function find(string $email): ?LoginLockout
    {
        $row = LoginLockout::where('email', self::normalize($email))->first();
        if ($row && ! $row->isManual() && ! $row->isLocked()
            && $row->last_attempt_at?->lt(now()->subHours(self::RESET_AFTER_HOURS))) {
            $row->delete();

            return null;
        }

        return $row;
    }

    /**
     * Status untuk layar masuk.
     *
     * @return array{locked: bool, manual: bool, seconds: ?int, level: int, failed: int, attempts_left: ?int}
     */
    public function status(string $email): array
    {
        $row = $this->find($email);
        $failed = $row?->failed_count ?? 0;
        $level = $row?->level ?? 0;
        $locked = (bool) $row?->isLocked();

        return [
            'locked' => $locked,
            'manual' => (bool) $row?->isManual(),
            'seconds' => $locked ? $row->secondsLeft() : 0,
            'level' => $level,
            'failed' => $failed,
            'attempts_left' => $failed > 0 && ! $locked && $level < 3 ? self::maxAttempts() * ($level + 1) - $failed : null,
        ];
    }

    /** Catat satu kegagalan; naikkan tingkat kunci bila mencapai ambang. */
    public function fail(string $email, ?string $ip, ?User $user = null): LoginLockout
    {
        $email = self::normalize($email);
        $this->find($email); // lupakan hitungan basi

        return DB::transaction(function () use ($email, $ip, $user) {
            $row = LoginLockout::where('email', $email)->lockForUpdate()->first()
                ?? LoginLockout::create(['email' => $email, 'user_id' => $user?->id]);

            $ips = collect($row->ip_addresses ?? [])->push($ip)->filter()->unique()->take(-10)->values()->all();
            $row->fill(['failed_count' => $row->failed_count + 1, 'last_attempt_at' => now(), 'ip_addresses' => $ips, 'user_id' => $user?->id ?? $row->user_id]);

            $level = intdiv($row->failed_count, self::maxAttempts());
            if ($level > $row->level && $level <= 3) {
                $minutes = self::TIER_MINUTES[$level];
                $row->fill(['level' => $level, 'locked_at' => now(), 'locked_until' => $minutes === null ? null : now()->addMinutes($minutes)]);
                $this->recordLock($row, $user);
            }
            $row->save();

            return $row;
        });
    }

    public function clear(string $email): void
    {
        LoginLockout::where('email', self::normalize($email))->delete();
    }

    /** Admin membuka kunci: hitungan dihapus, tercatat di audit. */
    public function unlock(LoginLockout $row, ?string $note = null): void
    {
        $row->delete();
        AuditLog::record('auth.unlocked', 'auth', "Kunci masuk {$row->email} dibuka (tingkat {$row->level}, {$row->failed_count}× gagal)",
            $row->user_id ? ['type' => 'user', 'id' => $row->user_id, 'label' => $row->user?->name] : null,
            ['email' => $row->email, 'level' => $row->level, 'failed' => $row->failed_count, 'note' => $note], reason: $note);
    }

    private function recordLock(LoginLockout $row, ?User $user): void
    {
        $what = $row->locked_until ? 'dikunci '.self::TIER_MINUTES[$row->level].' menit' : 'dikunci sampai dibuka Admin';
        AuditLog::record('auth.lockout', 'auth', "Masuk {$row->email} {$what} (tingkat {$row->level}) setelah {$row->failed_count}× gagal",
            null, ['email' => $row->email, 'level' => $row->level, 'failed' => $row->failed_count, 'ip_addresses' => $row->ip_addresses],
            null, $user ? ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->name] : ['name' => $row->email]);

        if ($row->locked_until === null) {
            Notification::firstOrCreate(['dedupe_key' => 'login:kunci:'.md5($row->email.'|'.now()->toDateTimeString())], [
                'type' => 'sistem',
                'target' => 'admin',
                'title' => 'Akun terkunci: '.($user?->name ?? $row->email),
                'message' => "Masuk {$row->email} dikunci setelah {$row->failed_count}× salah kata sandi dari "
                    .count($row->ip_addresses ?? []).' alamat IP. Buka kunci di Kelola Pengguna → Akun terkunci bila memang pemiliknya.',
                'url' => route('pengguna.terkunci', [], false),
            ]);
        }
    }
}
