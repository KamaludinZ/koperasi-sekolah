<?php

namespace App\Http\Middleware;

use App\Services\AuditLog;
use App\Services\AuthService;
use App\Support\Menu;
use App\Support\SessionPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jaga sesi petugas yang sedang masuk:
 * - akun dinonaktifkan / tanpa peran → langsung dikeluarkan;
 * - tidak ada aktivitas melebihi batas menganggur (SessionPolicy::idleMinutes) → sesi diakhiri.
 *   Polling latar (wire:poll) tidak dihitung sebagai aktivitas.
 * Keduanya dicatat di audit dan diarahkan ke /masuk dengan pesan (JSON: 401).
 */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user(); // hanya sesi petugas; portal anggota memakai guard `member`
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $reason = null;
        $user->loadMissing('role', 'roles');
        if (! $user->is_active) {
            $reason = 'Akun Anda dinonaktifkan oleh admin.';
            AuditLog::record('auth.forced_logout', 'auth', "{$user->name} dikeluarkan karena akun dinonaktifkan");
        } elseif ($user->roleNames() === []) {
            $reason = 'Akun Anda tidak lagi memiliki peran. Minta admin koperasi menetapkan perannya.';
            AuditLog::record('auth.forced_logout', 'auth', "{$user->name} dikeluarkan karena akunnya tidak memiliki peran", null, ['reason' => 'tanpa_peran']);
        } elseif (($idle = SessionPolicy::idleMinutes()) > 0
            && ($last = $request->session()->get('last_activity_at'))
            && now()->timestamp - $last > $idle * 60) {
            $reason = "Sesi berakhir karena tidak ada aktivitas selama {$idle} menit. Silakan masuk lagi.";
            AuditLog::record('auth.timeout', 'auth', "Sesi {$user->name} berakhir otomatis (tidak aktif {$idle} menit)");
        }

        if ($reason) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, $reason);
            }

            return redirect()->guest(route('login'))->with('status', $reason);
        }

        // cek versi menu berkala (GET /akun/menu) bukan aktivitas petugas — tidak memperpanjang sesi menganggur
        if ((! SessionPolicy::isPassive($request) && ! $request->routeIs('akun.menu')) || ! $request->session()->has('last_activity_at')) {
            $request->session()->put('last_activity_at', now()->timestamp);
        }

        $response = $next($request);
        // sidik hak akses terkini: halaman yang sedang terbuka tahu bila peran/hak menunya diubah admin
        if (Auth::guard('web')->check()) {
            $response->headers->set('X-Menu-Version', Menu::versionFor(AuthService::toArray(Auth::guard('web')->user()->loadMissing('role', 'roles'))));
        }

        return $response;
    }
}
