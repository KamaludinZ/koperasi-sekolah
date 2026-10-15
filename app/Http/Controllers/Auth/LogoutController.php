<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Impersonasi\StopController;
use App\Services\AuthService;
use App\Services\ImpersonationService;
use App\Support\ImpersonationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /keluar — akhiri sesi petugas (dicatat di audit) lalu kembali ke halaman masuk
 * (JSON: 200 {message}).
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, AuthService $auth): RedirectResponse|JsonResponse
    {
        // "Keluar" saat impersonasi = kembali ke akun admin, bukan mengeluarkan admin
        if (ImpersonationSession::active()) {
            return app(StopController::class)($request, app(ImpersonationService::class));
        }
        $auth->logout($request);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Anda sudah keluar.']);
        }

        return redirect()->route('login')->with('status', 'Anda sudah keluar. Sampai jumpa lagi.');
    }
}
