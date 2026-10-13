<?php

namespace App\Http\Controllers\Pengguna;

use App\Http\Controllers\Controller;
use App\Models\LoginLockout;
use App\Services\LoginLockoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Akun terkunci (menu Pengguna, khusus Admin).
 *
 * GET  /pengguna/terkunci/data → {data: [{id, email, user_id, name, role, failed, level, locked_at,
 *      locked_until, seconds_left, last_attempt_at, ip_addresses}], tiers, summary}
 * POST /pengguna/terkunci/{lockout}/buka {catatan?} → buka kunci; catatan wajib untuk tingkat 3.
 */
class LockoutController extends Controller
{
    public function index(LoginLockoutService $lockouts): JsonResponse
    {
        $rows = collect($lockouts->list());

        return response()->json([
            'data' => $rows->values(),
            'tiers' => LoginLockoutService::tiers(),
            'summary' => [
                'total' => $rows->count(),
                'manual' => $rows->whereNull('locked_until')->count(),
                'temporary' => $rows->whereNotNull('locked_until')->count(),
                'unknown' => $rows->whereNull('user_id')->count(),
            ],
        ]);
    }

    public function unlock(Request $request, LoginLockout $lockout, LoginLockoutService $lockouts): JsonResponse
    {
        $data = $request->validate(
            ['catatan' => [$lockout->level >= 3 ? 'required' : 'nullable', 'string', 'max:200']],
            ['catatan.required' => 'Tulis alasan membuka kunci (wajib untuk kunci tingkat akhir).', 'catatan.max' => 'Catatan maksimal 200 karakter.'],
        );

        $lockouts->unlock($lockout, $data['catatan'] ?? null);

        return response()->json(['message' => ($lockout->user?->name ?? $lockout->email).' dapat mencoba masuk lagi.']);
    }
}
