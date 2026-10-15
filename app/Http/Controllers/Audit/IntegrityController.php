<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditIntegrityFailure;
use App\Models\Role;
use App\Services\AuditVerificationService;
use App\Support\AuditVerificationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Hasil verifikasi integritas jejak audit (checksum HMAC & rantai sesi impersonasi):
 * - GET  /audit/integritas            → pemeriksaan penuh terakhir + ringkasan penanda gagal (terbuka per jenis);
 * - GET  /audit/integritas/kegagalan  → daftar penanda (?status=terbuka|pulih|semua&jenis=checksum|rantai), berhalaman;
 * - POST /audit/integritas/periksa    → jalankan pemeriksaan penuh sekarang (Admin; 3× per 10 menit per admin).
 * Admin & pengurus boleh membaca.
 */
class IntegrityController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'last' => AuditVerificationStatus::last(),
            'open_failures' => [
                'total' => AuditIntegrityFailure::open()->count(),
                'by_kind' => collect(AuditIntegrityFailure::KINDS)->map(fn ($l, $k) => AuditIntegrityFailure::open()->where('kind', $k)->count())->all(),
                'oldest_detected_at' => AuditIntegrityFailure::open()->min('first_detected_at'),
            ],
            'schedule' => ['penuh' => 'setiap hari 02.30', 'cepat' => 'setiap jam (menit ke-17): catatan peran & impersonasi'],
            'healthy' => AuditIntegrityFailure::open()->doesntExist() && (AuditVerificationStatus::last()['ok'] ?? true),
        ]]);
    }

    public function failures(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['terbuka', 'pulih', 'semua'])],
            'jenis' => ['nullable', Rule::in(array_keys(AuditIntegrityFailure::KINDS))],
            'per_halaman' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = AuditIntegrityFailure::query()
            ->when(($f['status'] ?? 'terbuka') === 'terbuka', fn ($q) => $q->whereNull('cleared_at'))
            ->when(($f['status'] ?? null) === 'pulih', fn ($q) => $q->whereNotNull('cleared_at'))
            ->when($f['jenis'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->orderByDesc('last_detected_at')->orderByDesc('id')
            ->paginate((int) ($f['per_halaman'] ?? 20));
        $logs = ActivityLog::query()->whereIn('id', collect($page->items())->pluck('activity_log_id')->filter()->all())
            ->get(['id', 'event', 'description', 'actor_name', 'created_at'])->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditIntegrityFailure $x) => [
                'id' => $x->id, 'kind' => $x->kind, 'kind_label' => AuditIntegrityFailure::KINDS[$x->kind] ?? $x->kind, 'detail' => $x->detail,
                'activity_log' => $x->activity_log_id ? (fn (?ActivityLog $l) => ['id' => $x->activity_log_id, 'exists' => $l !== null,
                    'event' => $l->event ?? $x->event, 'description' => $l?->description, 'actor_name' => $l?->actor_name,
                    'at' => $l?->created_at?->toIso8601String()])($logs->get($x->activity_log_id)) : null,
                'impersonation_id' => $x->impersonation_id,
                'first_detected_at' => $x->first_detected_at->toIso8601String(), 'last_detected_at' => $x->last_detected_at->toIso8601String(),
                'cleared_at' => $x->cleared_at?->toIso8601String(), 'open' => $x->cleared_at === null,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function run(Request $request, AuditVerificationService $verifier): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($user?->hasRole(Role::ADMIN), 403, 'Hanya Admin yang dapat menjalankan pemeriksaan.');
        $key = 'verifikasi-audit-manual:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Terlalu sering — coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.'], 429);
        }
        RateLimiter::hit($key, 600);
        $s = $verifier->run('penuh', 'manual', $user->name);

        return response()->json(['data' => [
            'ok' => $s['invalid'] === [] && $s['chain_failures'] === 0,
            'checked' => $s['checked'], 'chains_checked' => $s['chains_checked'], 'unsigned' => $s['unsigned'],
            'invalid' => $s['invalid'], 'chain_failures' => $s['chain_failures'], 'new_failures' => $s['new_failures'], 'open_failures' => $s['open_failures'],
            'last' => AuditVerificationStatus::last(),
        ]]);
    }
}
