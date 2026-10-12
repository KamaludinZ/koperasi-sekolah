<?php

namespace App\Http\Controllers\Akun;

use App\Http\Controllers\Controller;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesi login akun yang sedang masuk (JSON):
 * - GET    /akun/sesi/daftar                 → daftar sesi aktif
 * - DELETE /akun/sesi/{session}              → akhiri satu sesi lain
 * - POST   /akun/sesi/keluarkan-lain {password} → keluar dari semua perangkat lain
 */
class SessionController extends Controller
{
    public function index(Request $request, SessionService $sessions): JsonResponse
    {
        return response()->json(['data' => $sessions->list($request->user())]);
    }

    public function destroy(Request $request, string $session, SessionService $sessions): JsonResponse
    {
        $ok = $sessions->revoke($request->user(), $session);

        return response()->json(['message' => $ok ? 'Sesi diakhiri.' : 'Sesi tidak ditemukan.'], $ok ? 200 : 404);
    }

    public function destroyOthers(Request $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']], ['password.required' => 'Masukkan kata sandi Anda untuk konfirmasi.']);

        return response()->json(['revoked' => $sessions->revokeOthers($request->user(), $data['password'])]);
    }
}
