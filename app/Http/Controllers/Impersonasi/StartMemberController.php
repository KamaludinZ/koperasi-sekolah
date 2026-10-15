<?php

namespace App\Http\Controllers\Impersonasi;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /pengguna/impersonasi/anggota/{member} — Admin membuka portal sebagai anggota {member} (mode lihat saja).
 * Body: reason (10–200), minutes (10|15|30). Berhasil: arahkan ke beranda portal (JSON 201 {data: {..., redirect}});
 * ditolak: 422 dengan alasannya.
 */
class StartMemberController extends Controller
{
    public function __invoke(Request $request, Member $member, ImpersonationService $impersonation): RedirectResponse|JsonResponse
    {
        $record = $impersonation->startMember($request->user('web'), $member, (string) $request->input('reason', ''), (int) $request->input('minutes', 0), $request);
        $redirect = route('portal', absolute: false);

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $record->id, 'target' => ['type' => 'anggota', 'id' => $member->id, 'name' => $record->target_name, 'label' => $record->target_label],
                'expires_at' => $record->expires_at->toIso8601String(), 'redirect' => $redirect,
            ]], 201);
        }

        return redirect($redirect);
    }
}
