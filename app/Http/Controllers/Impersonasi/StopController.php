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
            return response()->json($result['ok']
                ? ['ok' => true, 'message' => "Kembali sebagai {$result['admin']->name}.", 'admin' => ['id' => $result['admin']->id, 'name' => $result['admin']->name],
                    'ended' => ['target_name' => $result['target_name']], 'redirect' => $impersonation->returnUrl($result)]
                : ['ok' => false, 'message' => $result['target_name'] ? 'Sesi impersonasi ditutup. Silakan masuk lagi.' : 'Tidak ada sesi impersonasi yang aktif.']);
        }

        return $impersonation->stopResponse($result);
    }
}
