<?php

namespace App\Http\Middleware;

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
        if (! $member) {
            if ($request->expectsJson()) {
                abort(401, 'Silakan masuk portal anggota.');
            }

            return redirect()->guest(route('portal.login'));
        }
        // admin yang masuk sebagai anggota: aksi uang & PIN ditolak di server (tombolnya juga dinonaktifkan di tampilan)
        if (ImpersonationSession::impersonatingMember() && $request->routeIs(...ImpersonationPolicy::MEMBER_READ_ONLY_ROUTES)) {
            abort(403, ImpersonationPolicy::MEMBER_READ_ONLY_MESSAGE);
        }

        return $next($request);
    }
}
