<?php

namespace App\Http\Controllers\Impersonasi;

use App\Http\Controllers\Controller;
use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\RoleImpersonationTrail;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Endpoint umum impersonasi (Admin):
 * - POST /pengguna/impersonasi {jenis: petugas|anggota, id, alasan (atau reason), menit (atau minutes)} → mulai sesi
 *   lewat ImpersonationService (aturan, validasi alasan & lama sesi, jejak audit ber-HMAC); JSON 201 / arahkan;
 * - GET /pengguna/impersonasi/aktif → sesi impersonasi milik admin ini yang masih terbuka (atau null).
 */
class ImpersonationController extends Controller
{
    public function store(Request $request, ImpersonationService $impersonation): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['required', 'in:petugas,anggota'],
            'id' => ['required', 'integer', 'min:1'],
        ], ['jenis.in' => 'Jenis akun harus petugas atau anggota.']);
        $reason = (string) ($request->input('alasan') ?? $request->input('reason') ?? '');
        $minutes = (int) ($request->input('menit') ?? $request->input('minutes') ?? 0);
        $admin = $request->user('web');

        if ($data['jenis'] === 'anggota') {
            $member = Member::findOrFail($data['id']);
            $record = $impersonation->startMember($admin, $member, $reason, $minutes, $request);
            $redirect = route('portal', absolute: false);
        } else {
            $target = User::findOrFail($data['id']);
            $record = $impersonation->startStaff($admin, $target, $reason, $minutes, $request);
            $target->load('role', 'roles');
            $redirect = route(Menu::homeRoute($target->roleName(), $target->permissions()), absolute: false);
        }

        if ($request->expectsJson()) {
            return response()->json(['data' => self::present($record) + ['redirect' => $redirect]], 201);
        }

        return redirect($redirect);
    }

    public function active(Request $request): JsonResponse
    {
        $record = Impersonation::query()->where('admin_id', $request->user('web')->id)->open()->latest('started_at')->first();

        return response()->json(['data' => $record ? self::present($record) : null]);
    }

    public static function present(Impersonation $r): array
    {
        return [
            'id' => $r->id,
            'target' => ['type' => $r->target_type, 'id' => $r->target_user_id ?? $r->target_member_id, 'name' => $r->target_name, 'label' => $r->target_label],
            'reason' => $r->reason,
            'started_at' => $r->started_at->toIso8601String(),
            'expires_at' => $r->expires_at->toIso8601String(),
            'ended_at' => $r->ended_at?->toIso8601String(),
            'end_reason' => $r->end_reason,
            'active' => $r->isActive(),
            'remaining_seconds' => $r->isActive() ? max(0, $r->expires_at->getTimestamp() - now()->getTimestamp()) : 0,
            'start_log_id' => $r->start_log_id,
            'actions_recorded' => RoleImpersonationTrail::actionCount($r->id),
        ];
    }
}
