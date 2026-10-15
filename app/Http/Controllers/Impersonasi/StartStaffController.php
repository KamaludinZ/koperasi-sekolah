<?php

namespace App\Http\Controllers\Impersonasi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /pengguna/impersonasi/petugas/{user} — Admin mulai masuk sebagai akun petugas {user}.
 * Body: reason (10–200), minutes (10|15|30). Berhasil: arahkan ke halaman awal akun target
 * (JSON 201 {data: {id, target, expires_at, redirect}}); ditolak: 422 dengan alasannya.
 */
class StartStaffController extends Controller
{
    public function __invoke(Request $request, User $user, ImpersonationService $impersonation): RedirectResponse|JsonResponse
    {
        $admin = $request->user('web');
        $record = $impersonation->startStaff($admin, $user, (string) $request->input('reason', ''), (int) $request->input('minutes', 0), $request);
        $redirect = route(Menu::homeRoute($user->roleName(), $user->fresh(['role', 'roles'])->permissions()), absolute: false);

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'id' => $record->id, 'target' => ['type' => 'petugas', 'id' => $user->id, 'name' => $record->target_name, 'label' => $record->target_label],
                'expires_at' => $record->expires_at->toIso8601String(), 'redirect' => $redirect,
            ]], 201);
        }

        return redirect($redirect);
    }
}
