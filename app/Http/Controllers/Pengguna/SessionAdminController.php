<?php

namespace App\Http\Controllers\Pengguna;

use App\Http\Controllers\Controller;
use App\Services\SessionService;
use App\Support\SessionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesi aktif semua petugas (Admin, JSON):
 * - GET    /pengguna/sesi-aktif/data              → sesi yang aktif dalam batas menganggur + ringkasan
 * - DELETE /pengguna/sesi-aktif/{session} {alasan} → paksa keluar satu sesi (bukan sesi sendiri), tercatat di audit
 */
class SessionAdminController extends Controller
{
    public function index(SessionService $sessions): JsonResponse
    {
        $rows = collect($sessions->allActive());

        return response()->json([
            'data' => $rows->all(),
            'meta' => [
                'idle_minutes' => SessionPolicy::idleMinutes(),
                'total' => $rows->count(),
                'users' => $rows->pluck('user_id')->unique()->count(),
            ],
        ]);
    }

    public function destroy(Request $request, string $session, SessionService $sessions): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [
            'alasan.required' => 'Tulis alasan (min. 5 karakter).',
            'alasan.min' => 'Tulis alasan (min. 5 karakter).',
            'alasan.max' => 'Alasan maksimal 200 karakter.',
        ]);

        try {
            $ok = $sessions->forceEnd($session, $request->user(), $data['alasan']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->errors()['reason'][0], 'errors' => ['alasan' => $e->errors()['reason']]], 422);
        }

        return response()->json(['message' => $ok ? 'Sesi diakhiri. Petugas harus masuk lagi di perangkat itu.' : 'Sesi sudah tidak ada.'], $ok ? 200 : 404);
    }
}
