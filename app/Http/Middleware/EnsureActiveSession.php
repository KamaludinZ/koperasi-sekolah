<?php

namespace App\Http\Middleware;

use App\Services\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jaga sesi petugas yang sedang masuk:
 * - akun dinonaktifkan / tanpa peran → langsung dikeluarkan;
 * - tidak ada aktivitas melebihi `koperasi.idle_minutes` → sesi diakhiri.
 * Keduanya dicatat di audit dan diarahkan ke /masuk dengan pesan (JSON: 401).
 */
class EnsureActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $reason = null;
        $user->loadMissing('role');
        if (! $user->is_active || ! $user->role) {
            $reason = 'Akun Anda dinonaktifkan oleh admin.';
            AuditLog::record('auth.forced_logout', 'auth', "{$user->name} dikeluarkan karena akun dinonaktifkan");
        } elseif (($idle = (int) config('koperasi.idle_minutes')) > 0
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

        $request->session()->put('last_activity_at', now()->timestamp);

        return $next($request);
    }
}
