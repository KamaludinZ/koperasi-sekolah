<?php

namespace App\Services;

use App\Models\Impersonation;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationPolicy;
use App\Support\ImpersonationSession;
use App\Support\Menu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Layanan impersonasi (admin masuk sementara sebagai akun lain untuk membantu).
 * Keadaan sesi: lihat App\Support\ImpersonationSession. Petugas: guard `web` berpindah ke akun target, id admin
 * disimpan di sesi. Anggota: guard `web` tetap admin, guard `member` masuk sebagai anggota target.
 */
class ImpersonationService
{
    /**
     * Mulai impersonasi akun PETUGAS: aturan dicek ulang di server (ImpersonationPolicy), sesi lama admin yang masih
     * terbuka ditutup, baris impersonation_sessions dibuat, id sesi diganti (cegah fiksasi), lalu guard `web`
     * berpindah ke akun target tanpa "ingat saya" & tanpa mengubah last_login_at target. Id admin disimpan di sesi
     * sehingga "Kembali ke akun saya" bisa memulihkannya.
     *
     * @throws ValidationException alasan/lama sesi tidak sah atau akun tidak boleh dimasuki
     */
    public function startStaff(User $admin, User $target, string $reason, int $minutes, Request $request): Impersonation
    {
        [$reason, $minutes] = $this->validated($reason, $minutes);
        $admin->loadMissing('role', 'roles');
        $target->loadMissing('role', 'roles');
        if ($denial = ImpersonationPolicy::actorDenial($admin) ?? ImpersonationPolicy::staffDenial($target, $admin)) {
            throw ValidationException::withMessages(['target' => $denial]);
        }

        $label = collect($target->roleNames())->map(fn ($r) => Role::LABELS[$r] ?? $r)->join(' · ');
        $record = $this->open($admin, 'petugas', $target->id, null, $target->name, $label, $reason, $minutes, $request);

        session()->regenerate();
        $this->remember($request, $record);
        Auth::guard('web')->login($target); // tanpa remember: sesi impersonasi tidak pernah dipulihkan dari cookie

        return $record;
    }

    /**
     * Mulai impersonasi ANGGOTA PORTAL lewat jalur login anggota yang sudah ada (guard `member`, PortalAuthService):
     * guard `web` tetap milik admin; portal berjalan dalam mode lihat saja (ImpersonationPolicy::MEMBER_READ_ONLY_ROUTES).
     *
     * @throws ValidationException alasan/lama sesi tidak sah atau anggota tidak boleh dimasuki
     */
    public function startMember(User $admin, Member $member, string $reason, int $minutes, Request $request): Impersonation
    {
        [$reason, $minutes] = $this->validated($reason, $minutes);
        $admin->loadMissing('role', 'roles');
        if ($denial = ImpersonationPolicy::actorDenial($admin) ?? ImpersonationPolicy::memberDenial($member)) {
            throw ValidationException::withMessages(['target' => $denial]);
        }

        $label = $member->member_code.($member->class ? ' · '.$member->class : '');
        $record = $this->open($admin, 'anggota', null, $member->id, $member->name, $label, $reason, $minutes, $request);

        session()->regenerate();
        $this->remember($request, $record);
        app(PortalAuthService::class)->enterAs($member);

        return $record;
    }

    /**
     * Mulai impersonasi dari dialog Livewire (halaman Masuk sebagai akun) lalu arahkan ke halaman awal akun target.
     * Galat validasi dikembalikan ke dialog.
     */
    public function startFromLivewire(Component $component, User $admin, User|Member $target, string $reason, int $minutes): mixed
    {
        try {
            if ($target instanceof Member) {
                $this->startMember($admin, $target, $reason, $minutes, request());

                return $component->redirectRoute('portal', navigate: false);
            }
            $this->startStaff($admin, $target, $reason, $minutes, request());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $component->addError($field, $messages[0]);
            }

            return null;
        }

        return $component->redirectRoute(Menu::homeRoute($target->roleName(), $target->permissions()), navigate: false);
    }

    /** @return array{0: string, 1: int} alasan (spasi dirapikan) & lama sesi yang sah */
    private function validated(string $reason, int $minutes): array
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        Validator::make(['reason' => $reason, 'minutes' => $minutes], ImpersonationPolicy::reasonRules(), ImpersonationPolicy::reasonMessages())->validate();

        return [$reason, $minutes];
    }

    /** Tutup sesi impersonasi admin yang masih terbuka lalu buat baris baru (dalam satu transaksi). */
    private function open(User $admin, string $type, ?int $userId, ?int $memberId, string $name, ?string $label, string $reason, int $minutes, Request $request): Impersonation
    {
        return DB::transaction(function () use ($admin, $type, $userId, $memberId, $name, $label, $reason, $minutes, $request) {
            Impersonation::where('admin_id', $admin->id)->whereNull('ended_at')->lockForUpdate()->get()
                ->each(fn (Impersonation $old) => $this->closeRecord($old, $old->expires_at->isPast() ? 'kedaluwarsa' : 'sesi_berakhir'));

            $record = Impersonation::create([
                'admin_id' => $admin->id, 'admin_name' => mb_substr($admin->name, 0, 120),
                'target_type' => $type, 'target_user_id' => $userId, 'target_member_id' => $memberId,
                'target_name' => mb_substr($name, 0, 120), 'target_label' => $label !== null ? mb_substr($label, 0, 120) : null,
                'reason' => $reason, 'started_at' => now(), 'expires_at' => now()->addMinutes($minutes),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ]);
            $this->auditStart($admin, $record, $minutes);

            return $record;
        });
    }

    /**
     * Catat mulainya impersonasi di activity_logs (ber-checksum HMAC): pelaku = admin (bukan akun target), subjek =
     * akun target, alasan, lama & batas waktu, jenis target, id sesi impersonasi, IP & perangkat. Wajib berhasil —
     * bila catatan gagal disimpan, impersonasi dibatalkan (transaksi digulung) agar tidak ada sesi tanpa jejak.
     */
    private function auditStart(User $admin, Impersonation $record, int $minutes): void
    {
        $isMember = $record->target_type === 'anggota';
        $log = AuditLog::record('impersonation.started', 'auth',
            "{$record->admin_name} masuk sebagai {$record->target_name}".($isMember ? ' (portal anggota)' : ' (petugas'.($record->target_label ? ' · '.$record->target_label : '').')'),
            ['type' => $isMember ? 'member' : 'user', 'id' => $record->target_member_id ?? $record->target_user_id,
                'label' => $record->target_name.($record->target_label ? ' · '.$record->target_label : '')],
            [
                'impersonation_id' => $record->id, 'target_type' => $record->target_type, 'minutes' => $minutes,
                'started_at' => $record->started_at->toIso8601String(), 'expires_at' => $record->expires_at->toIso8601String(),
            ],
            actor: AuthService::toArray($admin), reason: $record->reason);
        if (! $log) {
            throw new \RuntimeException('Jejak audit impersonasi gagal dicatat — impersonasi dibatalkan.');
        }
    }

    /** Simpan keadaan impersonasi di sesi (kontrak App\Support\ImpersonationSession). */
    private function remember(Request $request, Impersonation $record): void
    {
        session()->put(ImpersonationSession::KEY, [
            'id' => $record->id, 'admin_id' => $record->admin_id, 'admin_name' => $record->admin_name,
            'target_type' => $record->target_type, 'target_id' => $record->target_user_id ?? $record->target_member_id,
            'target_name' => $record->target_name, 'target_label' => $record->target_label, 'reason' => $record->reason,
            'started_at' => $record->started_at->timestamp, 'expires_at' => $record->expires_at->timestamp,
            'return_to' => self::safeReturnPath((string) ($request->input('return_to') ?: $request->headers->get('referer', ''))),
        ]);
        session()->put('last_activity_at', now()->timestamp);
    }

    /**
     * Halaman asal admin (untuk kembali setelah impersonasi) — hanya path internal aplikasi petugas: harus diawali
     * satu "/", host (bila ada) sama dengan aplikasi, bukan portal/livewire/rute keluar-masuk impersonasi, ≤ 255 karakter.
     * Selain itu null (cegah open redirect).
     */
    public static function safeReturnPath(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || $url === '' || strlen($url) > 2000) {
            return null;
        }
        if (isset($parts['host']) && strcasecmp($parts['host'], (string) parse_url(config('app.url'), PHP_URL_HOST)) !== 0
            && strcasecmp($parts['host'], (string) request()->getHost()) !== 0) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('#^/(portal|livewire|impersonasi|keluar|masuk)(/|$)#', $path)) {
            return null;
        }
        $full = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');

        return strlen($full) <= 255 ? $full : $path;
    }

    /**
     * Akhiri impersonasi dan kembalikan admin ke akunnya sendiri (satu klik dari spanduk/menu): guard dipulihkan,
     * keadaan impersonasi dihapus, id sesi diganti (cegah fiksasi sesi). Admin yang sudah nonaktif/terhapus tidak
     * dipulihkan — sesi diakhiri seluruhnya.
     *
     * @return array{ok: bool, admin: ?User, target_name: ?string}
     */
    public function stop(Request $request, string $endReason = 'manual'): array
    {
        $imp = ImpersonationSession::current();
        if (! $imp) {
            session()->forget(ImpersonationSession::KEY);

            return ['ok' => false, 'admin' => null, 'target_name' => null];
        }

        $actions = RoleImpersonationTrail::actionCount($imp['id']);
        $this->close($imp['id'], $endReason);
        $admin = User::with(['role', 'roles'])->find($imp['admin_id']);
        if ($imp['target_type'] === 'anggota') {
            Auth::guard('member')->logout();
            session()->forget('portal.pin_token');
        }
        session()->forget(ImpersonationSession::KEY);

        if (! $admin || ! $admin->is_active || ! $admin->hasRole(Role::ADMIN)) {
            Auth::guard('web')->logout();
            Auth::guard('member')->logout();
            session()->invalidate();
            session()->regenerateToken();

            return ['ok' => false, 'admin' => null, 'target_name' => $imp['target_name']];
        }

        if ($imp['target_type'] === 'petugas' || Auth::guard('web')->id() !== $admin->id) {
            Auth::guard('web')->login($admin);
        }
        session()->regenerate();
        session()->regenerateToken(); // identitas berganti → token CSRF lama tidak berlaku
        session()->put('last_activity_at', now()->timestamp);

        return ['ok' => true, 'admin' => $admin, 'target_name' => $imp['target_name'], 'return_to' => $imp['return_to'] ?? null, 'actions' => $actions];
    }

    /**
     * Periksa keabsahan impersonasi yang berjalan di sesi ini terhadap basis data & guard (dipanggil middleware di
     * setiap permintaan). Null = sah / tidak sedang impersonasi; selain itu [cara berakhir, pesan untuk admin].
     *
     * @return ?array{0: string, 1: string}
     */
    public function problem(): ?array
    {
        $imp = ImpersonationSession::current();
        if (! $imp) {
            return null;
        }
        $record = Impersonation::find($imp['id']);
        if (! $record || $record->ended_at !== null || $record->admin_id !== $imp['admin_id'] || $record->target_type !== $imp['target_type']
            || ($record->target_user_id ?? $record->target_member_id) !== $imp['target_id']) {
            return ['sesi_berakhir', 'Sesi impersonasi sudah ditutup.'];
        }
        if ($record->expires_at->isPast()) {
            return ['kedaluwarsa', 'Waktu impersonasi ('.$record->started_at->diffInMinutes($record->expires_at).' menit) habis.'];
        }

        $admin = User::with(['role', 'roles'])->find($imp['admin_id']);
        if (! $admin || ! $admin->is_active || ! $admin->hasRole(Role::ADMIN) || ! $admin->canOpen('pengguna')) {
            return ['dicabut', 'Hak admin pemilik sesi impersonasi ini sudah berubah.'];
        }
        if ($imp['target_type'] === 'petugas') {
            if (Auth::guard('web')->id() !== $imp['target_id']) {
                return ['sesi_berakhir', 'Sesi impersonasi tidak lagi sesuai dengan akun yang masuk.'];
            }
            $target = User::with(['role', 'roles'])->find($imp['target_id']);
            if (! $target || ($denial = ImpersonationPolicy::staffDenial($target, $admin)) !== null) {
                return ['dicabut', 'Akun '.$imp['target_name'].' tidak lagi bisa dimasuki'.(isset($denial) ? ' ('.rtrim($denial, '.').')' : '').'.'];
            }
        } else {
            if (Auth::guard('member')->id() !== $imp['target_id'] || Auth::guard('web')->id() !== $admin->id) {
                return ['sesi_berakhir', 'Sesi impersonasi tidak lagi sesuai dengan akun yang masuk.'];
            }
            $member = Member::find($imp['target_id']);
            if (! $member || ($denial = ImpersonationPolicy::memberDenial($member)) !== null) {
                return ['dicabut', 'Anggota '.$imp['target_name'].' tidak lagi bisa dibuka portalnya'.(isset($denial) ? ' ('.rtrim($denial, '.').')' : '').'.'];
            }
        }

        return null;
    }

    /** Tandai baris sesi impersonasi berakhir (sekali saja; baris yang sudah berakhir tidak diubah). */
    public function close(string $id, string $reason): void
    {
        Impersonation::whereKey($id)->whereNull('ended_at')->get()->each(fn (Impersonation $r) => $this->closeRecord($r, $reason));
    }

    /**
     * Tutup satu sesi impersonasi + catat `impersonation.ended` di activity_logs ber-HMAC (pelaku = admin pemilik
     * sesi, subjek = akun target, cara berakhir, durasi, waktu mulai/berakhir/batas). Waktu berakhir sesi yang lewat
     * batas dan baru ditutup belakangan (mis. oleh jadwal) dicatat = batas waktunya. Penutupan tetap terjadi walau
     * pencatatan gagal (keamanan lebih dulu); kegagalan dilaporkan.
     */
    public function closeRecord(Impersonation $record, string $reason): void
    {
        DB::transaction(function () use ($record, $reason) {
            $locked = Impersonation::whereKey($record->id)->whereNull('ended_at')->lockForUpdate()->first();
            if (! $locked) {
                return; // sudah ditutup proses lain — tidak dicatat dua kali
            }
            $endedAt = $reason === 'kedaluwarsa' && $locked->expires_at->isPast() ? $locked->expires_at : now();
            $locked->update(['ended_at' => $endedAt->max($locked->started_at), 'end_reason' => $reason]);
            $record->setRawAttributes($locked->getAttributes(), true);

            $isMember = $locked->target_type === 'anggota';
            $admin = $locked->admin_id ? User::with(['role', 'roles'])->find($locked->admin_id) : null;
            $log = AuditLog::record('impersonation.ended', 'auth',
                "{$locked->admin_name} selesai sebagai {$locked->target_name} — ".mb_strtolower(Impersonation::END_REASONS[$reason] ?? $reason)
                    .' ('.$locked->durationMinutes().' menit)',
                ['type' => $isMember ? 'member' : 'user', 'id' => $locked->target_member_id ?? $locked->target_user_id,
                    'label' => $locked->target_name.($locked->target_label ? ' · '.$locked->target_label : '')],
                [
                    'impersonation_id' => $locked->id, 'target_type' => $locked->target_type, 'end_reason' => $reason,
                    'duration_minutes' => $locked->durationMinutes(), 'started_at' => $locked->started_at->toIso8601String(),
                    'ended_at' => $locked->ended_at->toIso8601String(), 'expires_at' => $locked->expires_at->toIso8601String(),
                ],
                actor: $admin ? AuthService::toArray($admin) : ['id' => null, 'name' => $locked->admin_name, 'role' => 'admin'],
                reason: $locked->reason);
            if (! $log) {
                report(new \RuntimeException("Jejak akhir impersonasi {$locked->id} gagal dicatat."));
            }
        });
    }

    /**
     * Tutup semua sesi impersonasi yang sudah lewat batas waktu tapi belum ditutup (peramban ditutup tanpa menekan
     * "Kembali"). Dijalankan terjadwal; mengembalikan jumlah yang ditutup.
     */
    public function closeExpired(): int
    {
        $n = 0;
        Impersonation::open()->where('expires_at', '<=', now())->orderBy('started_at')->get()
            ->each(function (Impersonation $r) use (&$n) {
                $this->closeRecord($r, 'kedaluwarsa');
                $n++;
            });

        return $n;
    }

    /** Tujuan setelah kembali: halaman asal admin bila masih bisa ia buka, selain itu daftar Masuk sebagai akun. */
    public function returnUrl(array $result): string
    {
        $path = $result['return_to'] ?? null;
        if ($path && $result['admin']) {
            try {
                $route = Route::getRoutes()->match(Request::create($path, 'GET'));
            } catch (\Throwable) {
                $route = null;
            }
            if ($route?->getName() && Menu::canReach($route->getName(), AuthService::toArray($result['admin']))) {
                return $path;
            }
        }

        return route('pengguna.impersonasi', absolute: false);
    }

    /** Respons standar setelah stop(): kembali ke daftar akun impersonasi dengan toast, atau ke halaman masuk. */
    public function stopResponse(array $result): RedirectResponse
    {
        if ($result['ok']) {
            return redirect()->to($this->returnUrl($result))->with('toast', ['type' => 'success',
                'message' => "Anda kembali sebagai {$result['admin']->name}. Sesi sebagai {$result['target_name']} ditutup"
                    .(($result['actions'] ?? 0) > 0 ? " — {$result['actions']} aksi tercatat atas nama Anda." : '.')]);
        }
        if ($result['target_name'] === null) {
            return redirect()->to(Auth::guard('web')->check() ? route('beranda') : route('login'));
        }

        return redirect()->route('login')->with('status', 'Sesi impersonasi ditutup. Silakan masuk lagi.');
    }
}
