<?php

namespace App\Http\Middleware;

use App\Services\PortalAuthService;
use App\Support\ImpersonationPolicy;
use App\Support\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Wajib masuk sebagai anggota (guard `member`) untuk halaman portal. Sengaja TIDAK mengganti guard bawaan
 * (beda dengan `auth:member`), sehingga kode petugas yang memakai auth()->user() tidak pernah menerima
 * Member. Anggota yang dinonaktifkan/akses portalnya dicabut langsung dikeluarkan.
 */
class EnsurePortalMember
{
    public function handle(Request $request, Closure $next): mixed
    {
        $guard = Auth::guard('member');
        $member = $guard->user();

        if ($member && ! $member->hasPortalAccess()) {
            $guard->logout();
            $member = null;
        }
        // Kata sandi diganti/diatur ulang di tempat lain → sesi lama di perangkat ini berakhir.
        if ($member) {
            $stored = $request->session()->get(PortalAuthService::CREDENTIAL_KEY);
            if ($stored === null) {
                PortalAuthService::rememberCredential($member);
            } elseif (! hash_equals($stored, PortalAuthService::credentialFingerprint($member))) {
                $guard->logout();
                $request->session()->forget([PortalAuthService::CREDENTIAL_KEY, 'portal.pin_token']);
                $member = null;
                session()->flash('status', 'Kata sandi portal sudah diganti. Silakan masuk lagi dengan kata sandi baru.');
            }
        }
        if (! $member) {
            if ($request->expectsJson()) {
                abort(401, 'Silakan masuk portal anggota.');
            }

            return redirect()->guest(route('portal.login'));
        }
        // Kata sandi sementara / belum ada PIN → wajib Siapkan akun dulu (kecuali admin yang sedang impersonasi).
        if ($member->needsPortalSetup() && ! ImpersonationSession::impersonatingMember()
            && ! $request->routeIs('portal.mulai', 'portal.logout', 'portal.panduan', 'portal.panduan.*')) {
            if ($request->expectsJson()) {
                abort(409, 'Siapkan akun portal dulu: buat kata sandi & PIN Anda sendiri.');
            }

            return redirect()->route('portal.mulai');
        }
        // admin yang masuk sebagai anggota: aksi uang & PIN ditolak di server (tombolnya juga dinonaktifkan di tampilan)
        if (ImpersonationSession::impersonatingMember() && $request->routeIs(...ImpersonationPolicy::MEMBER_READ_ONLY_ROUTES)) {
            abort(403, ImpersonationPolicy::MEMBER_READ_ONLY_MESSAGE);
        }

        return $next($request);
    }
}
