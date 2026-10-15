<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use App\Support\ImpersonationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penanda & penjaga sesi impersonasi di setiap permintaan (petugas & portal):
 * - keadaan di sesi dicocokkan dengan baris impersonation_sessions dan guard yang sedang masuk; waktu habis, baris
 *   sudah ditutup, guard tidak cocok, admin kehilangan hak, atau target tak lagi layak → impersonasi diakhiri
 *   otomatis (tercatat caranya) dan admin dikembalikan ke akunnya (atau dikeluarkan bila haknya dicabut);
 * - respons selama impersonasi ditandai header X-Impersonation & tidak boleh disimpan cache peramban
 *   (halaman akun lain tidak muncul lagi lewat tombol kembali setelah sesi berakhir);
 * - id sesi impersonasi tersedia di $request->attributes('impersonation_id') untuk pencatat audit.
 */
class TrackImpersonation
{
    public function __construct(private ImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        // rute pengakhir (kembali/keluar) menutup impersonasi sendiri — tidak perlu (dan tidak boleh) dihalangi di sini
        if (! $request->hasSession() || ! $request->session()->has(ImpersonationSession::KEY)
            || $request->routeIs('impersonasi.selesai', 'logout', 'portal.logout')) {
            return $next($request);
        }
        if (! ImpersonationSession::current()) { // keadaan rusak: buang saja
            $request->session()->forget(ImpersonationSession::KEY);

            return $next($request);
        }

        if ($problem = $this->impersonation->problem()) {
            [$reason, $message] = $problem;
            $result = $this->impersonation->stop($request, $reason);
            $message .= $result['ok'] ? ' Anda kembali sebagai '.$result['admin']->name.'.' : ' Silakan masuk lagi.';

            if ($request->expectsJson() || $request->hasHeader('X-Livewire') || ! $request->isMethod('GET')) {
                return response()->json(['message' => $message, 'impersonation_ended' => $reason], 409);
            }
            if ($result['ok']) {
                return redirect()->route('pengguna.impersonasi')->with('toast', ['type' => 'info', 'message' => $message]);
            }

            return redirect()->route('login')->with('status', $message);
        }

        $imp = ImpersonationSession::current();
        $request->attributes->set('impersonation_id', $imp['id']);
        $response = $next($request);
        $response->headers->set('X-Impersonation', $imp['id']);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
