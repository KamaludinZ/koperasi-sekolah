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
     * Mulai impersonasi dari dialog Livewire (halaman Masuk sebagai akun) lalu arahkan ke halaman awal akun target.
     * Galat validasi dikembalikan ke dialog.
     */
    public function startFromLivewire(Component $component, User $admin, User|Member $target, string $reason, int $minutes): mixed
    {
        try {
            if ($target instanceof Member) {
                if (! method_exists($this, 'startMember')) {
                    throw ValidationException::withMessages(['target' => 'Impersonasi anggota portal belum tersedia.']);
                }
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
                ->each(fn (Impersonation $old) => $old->update([
                    'ended_at' => max(now(), $old->started_at), 'end_reason' => $old->expires_at->isPast() ? 'kedaluwarsa' : 'sesi_berakhir',
                ]));

            return Impersonation::create([
                'admin_id' => $admin->id, 'admin_name' => mb_substr($admin->name, 0, 120),
                'target_type' => $type, 'target_user_id' => $userId, 'target_member_id' => $memberId,
                'target_name' => mb_substr($name, 0, 120), 'target_label' => $label !== null ? mb_substr($label, 0, 120) : null,
                'reason' => $reason, 'started_at' => now(), 'expires_at' => now()->addMinutes($minutes),
                'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ]);
        });
    }

    /** Simpan keadaan impersonasi di sesi (kontrak App\Support\ImpersonationSession). */
    private function remember(Request $request, Impersonation $record): void
    {
        session()->put(ImpersonationSession::KEY, [
            'id' => $record->id, 'admin_id' => $record->admin_id, 'admin_name' => $record->admin_name,
            'target_type' => $record->target_type, 'target_id' => $record->target_user_id ?? $record->target_member_id,
            'target_name' => $record->target_name, 'target_label' => $record->target_label, 'reason' => $record->reason,
            'started_at' => $record->started_at->timestamp, 'expires_at' => $record->expires_at->timestamp,
        ]);
        session()->put('last_activity_at', now()->timestamp);
    }

    /**
     * Akhiri impersonasi dan kembalikan admin ke akunnya sendiri (satu klik dari spanduk/menu): guard dipulihkan,
     * keadaan impersonasi dihapus, id sesi diganti (cegah fiksasi sesi). Admin yang sudah nonaktif/terhapus tidak
     * dipulihkan — sesi diakhiri seluruhnya.
     *
     * @return array{ok: bool, admin: ?User, target_name: ?string}
     */
    public function stop(Request $request): array
    {
        $imp = ImpersonationSession::current();
        if (! $imp) {
            session()->forget(ImpersonationSession::KEY);

            return ['ok' => false, 'admin' => null, 'target_name' => null];
        }

        $this->close($imp['id'], 'manual');
        $admin = User::with(['role', 'roles'])->find($imp['admin_id']);
        if ($imp['target_type'] === 'anggota') {
            Auth::guard('member')->logout();
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
        session()->put('last_activity_at', now()->timestamp);

        return ['ok' => true, 'admin' => $admin, 'target_name' => $imp['target_name']];
    }

    /** Tandai baris sesi impersonasi berakhir (sekali saja; baris yang sudah berakhir tidak diubah). */
    public function close(string $id, string $reason): void
    {
        Impersonation::whereKey($id)->whereNull('ended_at')->get()
            ->each(fn (Impersonation $r) => $r->update(['ended_at' => max(now(), $r->started_at), 'end_reason' => $reason]));
    }

    /** Respons standar setelah stop(): kembali ke daftar akun impersonasi dengan toast, atau ke halaman masuk. */
    public function stopResponse(array $result): RedirectResponse
    {
        if ($result['ok']) {
            return redirect()->route('pengguna.impersonasi')->with('toast', ['type' => 'success',
                'message' => "Anda kembali sebagai {$result['admin']->name}. Sesi sebagai {$result['target_name']} ditutup."]);
        }
        if ($result['target_name'] === null) {
            return redirect()->to(Auth::guard('web')->check() ? route('beranda') : route('login'));
        }

        return redirect()->route('login')->with('status', 'Sesi impersonasi ditutup. Silakan masuk lagi.');
    }
}
