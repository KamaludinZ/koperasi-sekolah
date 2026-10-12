<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Sesi login petugas (driver session `database`, tabel `sessions`):
 * daftar perangkat yang sedang masuk, akhiri satu sesi, akhiri semua sesi lain
 * (wajib kata sandi), dan akhiri semua sesi milik akun (mis. saat dinonaktifkan).
 */
class SessionService
{
    /**
     * @return list<array{id: string, ip_address: ?string, device: string, browser: string, last_active: string, current: bool}>
     */
    public function list(User $user): array
    {
        $current = session()->getId();
        $rows = $this->available()
            ? DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            : collect();

        $list = $rows->map(fn ($s) => [
            'id' => $s->id,
            'ip_address' => $s->ip_address,
            ...self::describe((string) $s->user_agent),
            'last_active' => Carbon::createFromTimestamp($s->last_activity)->timezone(config('app.timezone'))->toDateTimeString(),
            'current' => hash_equals($current, $s->id),
        ])->values()->all();

        // Driver selain database (mis. saat test): tampilkan sesi ini saja.
        if (! collect($list)->contains('current', true)) {
            array_unshift($list, [
                'id' => $current, 'ip_address' => request()->ip(), ...self::describe((string) request()->userAgent()),
                'last_active' => now()->toDateTimeString(), 'current' => true,
            ]);
        }

        return $list;
    }

    /** Akhiri satu sesi lain milik akun ini. */
    public function revoke(User $user, string $sessionId): bool
    {
        if (hash_equals(session()->getId(), $sessionId)) {
            throw ValidationException::withMessages(['session' => 'Untuk sesi ini, gunakan tombol Keluar.']);
        }

        $deleted = $this->available() && DB::table('sessions')->where('user_id', $user->id)->where('id', $sessionId)->delete() > 0;
        if ($deleted) {
            AuditLog::record('session.revoked', 'auth', "{$user->name} mengakhiri satu sesi di perangkat lain", null, ['session' => substr($sessionId, 0, 8).'…']);
        }

        return $deleted;
    }

    /**
     * Keluarkan akun dari semua perangkat lain (kata sandi wajib, agar tidak bisa
     * dilakukan orang yang kebetulan memakai komputer yang masih login).
     */
    public function revokeOthers(User $user, string $password): int
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi salah.']);
        }

        Auth::logoutOtherDevices($password); // ganti hash "remember me" & sesi lain
        $count = $this->available()
            ? DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete()
            : 0;

        AuditLog::record('session.revoked_others', 'auth', "{$user->name} keluar dari semua perangkat lain ({$count} sesi)", null, ['count' => $count]);

        return $count;
    }

    /** Akhiri semua sesi milik akun (dipakai saat akun dinonaktifkan / sandi diatur ulang admin). */
    public function revokeAll(User $user): int
    {
        $user->forceFill(['remember_token' => null])->saveQuietly();

        return $this->available() ? DB::table('sessions')->where('user_id', $user->id)->delete() : 0;
    }

    /** @return array{device: string, browser: string} */
    public static function describe(string $agent): array
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            $agent === '' => 'Tidak diketahui',
            default => 'Peramban lain',
        };
        $device = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iPhone/iPad',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Perangkat lain',
        };

        return ['device' => $device, 'browser' => $browser];
    }

    private function available(): bool
    {
        return config('session.driver') === 'database' || Schema::hasTable('sessions');
    }
}
