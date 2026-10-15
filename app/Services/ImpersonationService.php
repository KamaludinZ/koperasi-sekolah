<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Layanan impersonasi (admin masuk sementara sebagai akun lain untuk membantu).
 * Keadaan sesi: lihat App\Support\ImpersonationSession. Petugas: guard `web` berpindah ke akun target, id admin
 * disimpan di sesi. Anggota: guard `web` tetap admin, guard `member` masuk sebagai anggota target.
 */
class ImpersonationService
{
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
            $request->session()->forget(ImpersonationSession::KEY);

            return ['ok' => false, 'admin' => null, 'target_name' => null];
        }

        $admin = User::with(['role', 'roles'])->find($imp['admin_id']);
        if ($imp['target_type'] === 'anggota') {
            Auth::guard('member')->logout();
        }
        $request->session()->forget(ImpersonationSession::KEY);

        if (! $admin || ! $admin->is_active || ! $admin->hasRole(Role::ADMIN)) {
            Auth::guard('web')->logout();
            Auth::guard('member')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return ['ok' => false, 'admin' => null, 'target_name' => $imp['target_name']];
        }

        if ($imp['target_type'] === 'petugas' || Auth::guard('web')->id() !== $admin->id) {
            Auth::guard('web')->login($admin);
        }
        $request->session()->regenerate();
        $request->session()->put('last_activity_at', now()->timestamp);

        return ['ok' => true, 'admin' => $admin, 'target_name' => $imp['target_name']];
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
