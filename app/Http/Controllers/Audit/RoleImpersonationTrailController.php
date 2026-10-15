<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Role;
use App\Services\RoleImpersonationTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * JSON jejak audit peran & impersonasi (admin & pengurus; kontrak sama dengan halaman Audit → Peran & impersonasi):
 * - GET /audit/peran-impersonasi/data?tampil=peran|impersonasi|aksi&periode&pelaku&q&jenis&kategori&status&segel&hal
 *   → daftar berhalaman + jumlah per tab, tiap baris membawa status checksum HMAC (rantai mulai–selesai untuk sesi);
 * - GET /audit/peran-impersonasi/sesi/{impersonation} → rincian sesi: rantai catatan & aksi yang dilakukan;
 * - GET /audit/peran-impersonasi/catatan/{log} → rincian satu perubahan peran (diff, dampak menu, segel).
 * IP & perangkat hanya untuk Admin.
 */
class RoleImpersonationTrailController extends Controller
{
    public function __construct(private RoleImpersonationTrail $trail) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'tampil' => ['nullable', Rule::in(['peran', 'impersonasi', 'aksi'])],
            'periode' => ['nullable', Rule::in(array_keys(RoleImpersonationTrail::PERIODS))],
            'pelaku' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:60'],
            'jenis' => ['nullable', Rule::in(array_keys(RoleImpersonationTrail::ROLE_KINDS))],
            'kategori' => ['nullable', Rule::in(array_keys(ActivityLog::CATEGORIES))],
            'status' => ['nullable', Rule::in(['aktif', 'selesai'])],
            'segel' => ['nullable', Rule::in(['rusak'])],
            'per_halaman' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $tab = $f['tampil'] ?? 'peran';
        $filters = ['period' => $f['periode'] ?? '30', 'actor' => $f['pelaku'] ?? '', 'search' => $f['q'] ?? '', 'kind' => $f['jenis'] ?? '',
            'category' => $f['kategori'] ?? '', 'status' => $f['status'] ?? '', 'seal' => $f['segel'] ?? ''];
        $per = (int) ($f['per_halaman'] ?? 15);
        $admin = (bool) $request->user('web')?->hasRole(Role::ADMIN);

        if ($tab === 'impersonasi') {
            $page = $this->trail->sessions($filters, $per);
            $data = collect($page->items())->map(fn (Impersonation $s) => $this->session($s, $admin))->all();
        } else {
            $page = $tab === 'aksi' ? $this->trail->actions($filters, $per) : $this->trail->roleChanges($filters, $per);
            $seal = $this->trail->integrity(collect($page->items())->pluck('id')->map(fn ($i) => (int) $i)->all());
            $data = collect($page->items())->map(fn (ActivityLog $l) => $this->log($l, $seal[$l->id] ?? false, $admin))->all();
        }

        return response()->json([
            'data' => $data,
            'meta' => ['tab' => $tab, 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'counts' => $this->trail->counts($filters), 'filters' => $filters],
        ]);
    }

    public function session(Impersonation $impersonation, ?bool $admin = null): array|JsonResponse
    {
        $asResponse = $admin === null;
        $admin ??= (bool) request()->user('web')?->hasRole(Role::ADMIN);
        $s = $impersonation;
        $row = [
            'id' => $s->id, 'admin' => ['id' => $s->admin_id, 'name' => $s->admin_name],
            'target' => ['type' => $s->target_type, 'id' => $s->target_user_id ?? $s->target_member_id, 'name' => $s->target_name, 'label' => $s->target_label],
            'reason' => $s->reason, 'started_at' => $s->started_at->toIso8601String(), 'expires_at' => $s->expires_at->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(), 'end_reason' => $s->end_reason,
            'end_reason_label' => $s->end_reason ? (Impersonation::END_REASONS[$s->end_reason] ?? $s->end_reason) : null,
            'duration_minutes' => $s->durationMinutes(), 'active' => $s->isActive(),
            'actions_recorded' => RoleImpersonationTrail::actionCount($s->id),
            'chain' => $this->trail->chain($s),
        ] + ($admin ? ['ip_address' => $s->ip_address, 'user_agent' => $s->user_agent] : []);

        if (! $asResponse) {
            return $row;
        }
        $actions = $s->actionLogs()->orderBy('id')->limit(200)->get();
        $seal = $this->trail->integrity($actions->pluck('id')->map(fn ($i) => (int) $i)->all());

        return response()->json(['data' => $row + ['actions' => $actions->map(fn ($l) => $this->log($l, $seal[$l->id] ?? false, $admin))->all()]]);
    }

    public function roleChange(int $log): JsonResponse
    {
        $d = $this->trail->roleChangeDetail($log);
        abort_unless($d !== null, 404, 'Catatan perubahan peran tidak ditemukan.');
        if (! request()->user('web')?->hasRole(Role::ADMIN)) {
            unset($d['actor']['ip'], $d['actor']['agent']);
        }
        $d['at'] = $d['at']->toIso8601String();

        return response()->json(['data' => $d]);
    }

    private function log(ActivityLog $l, bool $valid, bool $admin): array
    {
        return [
            'id' => $l->id, 'event' => $l->event, 'category' => $l->category, 'description' => $l->description,
            'actor' => ['id' => $l->actor_id, 'name' => $l->actor_name, 'role' => $l->actor_role],
            'subject' => ['type' => $l->subject_type, 'id' => $l->subject_id, 'label' => $l->subject_label],
            'reason' => $l->reason, 'at' => $l->created_at?->toIso8601String(),
            'impersonation' => $l->impersonation_id ? ['id' => $l->impersonation_id, 'on_behalf' => ['type' => $l->on_behalf_type, 'id' => $l->on_behalf_id,
                'name' => $l->properties['impersonation']['target_name'] ?? null]] : null,
            'diff' => array_intersect_key($l->properties ?? [], array_flip(['before', 'after', 'added', 'removed', 'primary_from', 'primary_to', 'menus_gained', 'menus_lost', 'source'])),
            'valid' => $valid,
        ] + ($admin ? ['ip_address' => $l->ip_address] : []);
    }
}
