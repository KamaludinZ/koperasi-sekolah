<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Services\AuditChangeQuery;
use App\Services\AuditQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoint JSON jejak audit (Admin & Pengurus lewat menu Audit; Kasir ditolak middleware).
 * Alamat IP & perangkat hanya dikirim ke Admin.
 *
 * GET /audit/data?tampil=semua|login|sirkulasi|data&kategori=&petugas=&q=&periode=|dari=&sampai=&per_page=&page=
 *     Semua aktivitas (terbaru dulu) + ringkasan periode.
 * GET /audit/perubahan/data?jenis=barang|kategori|anggota|pengaturan&aksi=dibuat|diubah|dihapus|dipulihkan
 *     &petugas=&q=&objek=jenis:id&sensitif=1&periode=|dari=&sampai=&per_page=&page=
 *     Perubahan data master dengan nilai sebelum → sesudah + ringkasan & daftar petugas.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, AuditQuery $audit): JsonResponse
    {
        $data = $request->validate($this->rangeRules() + [
            'tampil' => ['nullable', Rule::in(array_keys(AuditQuery::VIEWS))],
            'kategori' => ['nullable', Rule::in(array_keys(ActivityLog::CATEGORIES))],
            'petugas' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
        ], $this->messages());
        [$from, $to] = AuditQuery::range($data['periode'] ?? '7-hari', $data['dari'] ?? null, $data['sampai'] ?? null);
        $admin = $request->user()->hasRole(Role::ADMIN);

        $page = $audit->query([
            'view' => $data['tampil'] ?? 'semua', 'from' => $from, 'to' => $to,
            'category' => $data['kategori'] ?? '', 'actor' => $data['petugas'] ?? '', 'search' => $data['q'] ?? '',
        ])->paginate($data['per_page'] ?? 50)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $l) => [
                'id' => $l->id,
                'at' => $l->created_at->toDateTimeString(),
                'event' => $l->event,
                'category' => $l->category,
                'category_label' => $l->category_label,
                'action' => $l->action,
                'description' => $l->description,
                'subject' => $l->subject_type ? ['type' => $l->subject_type, 'id' => $l->subject_id, 'label' => $l->subject_label] : null,
                'actor' => ['id' => $l->actor_id, 'name' => $l->actor_name, 'role' => $l->actor_role],
                'amount' => $l->amount,
                'changes' => $l->action ? $l->field_changes : ($l->properties['changes'] ?? null),
                'reason' => $l->reason,
                'ip_address' => $admin ? $l->ip_address : null,
                'user_agent' => $admin ? $l->user_agent : null,
            ]),
            'summary' => $audit->summary($from, $to),
            'meta' => $this->meta($page, $from, $to),
        ]);
    }

    public function changes(Request $request, AuditChangeQuery $changes): JsonResponse
    {
        $data = $request->validate($this->rangeRules() + [
            'jenis' => ['nullable', Rule::in(array_keys(AuditChangeQuery::ENTITIES))],
            'aksi' => ['nullable', Rule::in(array_keys(ActivityLog::ACTIONS))],
            'petugas' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'objek' => ['nullable', 'regex:/^('.implode('|', array_keys(AuditChangeQuery::ENTITIES)).'):\d+$/'],
            'sensitif' => ['nullable', 'boolean'],
        ], $this->messages() + ['objek.regex' => 'Format objek: jenis:id, mis. barang:3.']);
        [$from, $to] = AuditQuery::range($data['periode'] ?? '30-hari', $data['dari'] ?? null, $data['sampai'] ?? null);
        $admin = $request->user()->hasRole(Role::ADMIN);

        $page = $changes->query([
            'entity' => $data['jenis'] ?? '', 'action' => $data['aksi'] ?? '', 'actor' => $data['petugas'] ?? '',
            'search' => $data['q'] ?? '', 'subject' => $data['objek'] ?? '', 'sensitive' => (bool) ($data['sensitif'] ?? false),
            'from' => $from, 'to' => $to,
        ])->paginate($data['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $l) => AuditChangeQuery::toArray($l, $admin) + [
                'sensitive' => AuditChangeQuery::isSensitive(AuditChangeQuery::toArray($l)),
            ]),
            'summary' => $changes->summary($from, $to),
            'subject_label' => isset($data['objek']) ? $changes->subjectLabel($data['objek']) : null,
            'meta' => $this->meta($page, $from, $to),
        ]);
    }

    private function rangeRules(): array
    {
        return [
            'periode' => ['nullable', Rule::in(array_keys(AuditQuery::PERIODS))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function messages(): array
    {
        return ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.', 'in' => 'Pilihan :attribute tidak dikenal.'];
    }

    private function meta($page, $from, $to): array
    {
        return [
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
