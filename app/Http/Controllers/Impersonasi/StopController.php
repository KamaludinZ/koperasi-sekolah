<?php

namespace App\Http\Controllers\Impersonasi;

use App\Http\Controllers\Controller;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /impersonasi/selesai — tombol "Kembali ke akun saya": akhiri impersonasi dan pulihkan sesi admin
 * (JSON: {ok, message}). Tanpa impersonasi aktif tidak mengubah apa pun.
 */
class StopController extends Controller
{
    public function __invoke(Request $request, ImpersonationService $impersonation): RedirectResponse|JsonResponse
    {
        $result = $impersonation->stop($request);
        if ($request->expectsJson()) {
            return response()->json(['ok' => $result['ok'], 'message' => $result['ok']
                ? "Kembali sebagai {$result['admin']->name}." : 'Tidak ada sesi impersonasi yang aktif.']);
        }

        return $impersonation->stopResponse($result);
    }
}
