<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Jobs\SyncGuides;
use App\Models\GuideDraft;
use App\Models\GuideEntry;
use App\Models\GuideSyncJob;
use App\Services\AuditLog;
use App\Services\GuideDraftService;
use App\Services\GuideGenerator;
use App\Services\GuideMetadataService;
use App\Services\GuideScanner;
use App\Services\GuideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hasil pemindaian & draf panduan untuk pengelola panduan (gate kelola-panduan), tanpa menyimpan apa pun:
 * - GET /panduan/pembaruan/pemindaian       → fakta per menu (halaman, peran), izin per peran, temuan konfigurasi,
 *   ringkasan, dan perubahan sejak pemindaian tersimpan terakhir (menu baru/hilang, aspek berubah);
 * - GET /panduan/pembaruan/draf             → rencana penyusunan ulang (baru, diperbarui + kolom, tetap, manual,
 *   menu yang sudah tiada) dan draf yang menunggu tinjauan (guide_drafts);
 * - GET /panduan/pembaruan/draf/{key}       → pratinjau satu draf dibanding isi tersimpan per bagian.
 * Pantau sinkronisasi:
 * - GET  /panduan/pembaruan/sinkron         → status terkini (sedang berjalan/mengantre, terakhir selesai & berhasil,
 *   jumlah per status, perubahan menu/izin yang belum disinkronkan, jadwal) + riwayat (?status=, ?per=);
 * - GET  /panduan/pembaruan/sinkron/{id}    → rincian satu proses;
 * - POST /panduan/pembaruan/sinkron         → antre sinkronisasi manual (202; 409 bila sudah ada yang mengantre/berjalan).
 * Panduan usang:
 * - GET    /panduan/pembaruan/usang          → panduan bertanda usang (alasan, sejak, bagian disunting, perubahan draf,
 *   draf menunggu tinjauan);
 * - POST   /panduan/pembaruan/usang/{key}    → {alasan} tandai perlu ditinjau (409 bila sudah dengan alasan sama);
 * - DELETE /panduan/pembaruan/usang/{key}    → {catatan?} tandai sudah ditinjau (409 bila tidak sedang usang);
 * - DELETE /panduan/pembaruan/usang          → {catatan?} tandai semua sudah ditinjau.
 * Semua penandaan & peninjauan tercatat di audit.
 */
class GuideUpdateController extends Controller
{
    public function scan(GuideScanner $scanner, GuideMetadataService $meta): JsonResponse
    {
        $facts = $scanner->scan();

        return $this->json([
            'menus' => $facts, 'roles' => $scanner->roles(), 'issues' => $scanner->issues($facts),
            'summary' => $scanner->summary($facts), 'changes' => $meta->diff(),
        ]);
    }

    public function drafts(Request $request, GuideGenerator $generator): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:'.implode(',', array_keys(GuideDraft::STATUSES))]]);
        $plan = $generator->plan();
        $titles = GuideEntry::pluck('title', 'key')->all();
        $pending = GuideDraft::with(['reviewer:id,name'])->where('status', $request->query('status', 'pending'))
            ->latest('updated_at')->limit(100)->get()
            ->map(fn (GuideDraft $d) => [
                'id' => $d->id, 'key' => $d->key, 'title' => $titles[$d->key] ?? ($d->payload['title'] ?? $d->key), 'draft_title' => $d->payload['title'] ?? null,
                'changed_fields' => $d->changed_fields, 'reason' => $d->reason, 'status' => $d->status, 'sync_job_id' => $d->sync_job_id,
                'outdated' => $d->status === 'pending' ? app(GuideDraftService::class)->isOutdated($d) : null,
                'created_at' => $d->created_at?->toIso8601String(), 'reviewed_by' => $d->reviewer?->name, 'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            ])->values();

        return $this->json([
            'plan' => $plan,
            'counts' => ['created' => count($plan['created']), 'updated' => count($plan['updated']), 'unchanged' => count($plan['unchanged']),
                'skipped_manual' => count($plan['skipped_manual']), 'orphaned' => count($plan['orphaned']), 'drafts' => $pending->count()],
            'drafts' => $pending,
        ]);
    }

    public function draft(string $key, GuideGenerator $generator): JsonResponse
    {
        $preview = $generator->preview($key);
        abort_if($preview === null, 404, 'Panduan tidak ditemukan di hasil pemindaian.');

        return $this->json($preview);
    }

    public function syncStatus(Request $request, GuideMetadataService $meta): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_keys(GuideSyncJob::STATUSES))], 'per' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $active = GuideSyncJob::whereIn('status', ['queued', 'running'])->latest('id')->get();
        $last = GuideSyncJob::whereIn('status', ['done', 'failed'])->latest('finished_at')->latest('id')->first();
        $ok = GuideSyncJob::where('status', 'done')->latest('finished_at')->latest('id')->first();
        $history = GuideSyncJob::with('user:id,name')->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate((int) ($f['per'] ?? 20));
        $diff = $meta->diff();
        $counts = GuideSyncJob::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'data' => [
                'state' => $active->firstWhere('status', 'running') ? 'running' : ($active->isNotEmpty() ? 'queued' : ($last?->status === 'failed' ? 'failed' : 'idle')),
                'active' => $active->map(fn ($j) => $this->presentJob($j))->values(),
                'last' => $last ? $this->presentJob($last) : null,
                'last_success_at' => $ok?->finished_at?->toIso8601String(),
                'counts' => array_map(fn ($s) => (int) ($counts[$s] ?? 0), array_combine(array_keys(GuideSyncJob::STATUSES), array_keys(GuideSyncJob::STATUSES))),
                'pending_changes' => ['trigger' => GuideMetadataService::triggerFor($diff)] + $diff,
                'schedule' => ['check' => 'tiap 5 menit (panduan:periksa)', 'full' => 'harian 03:30'],
            ],
            'history' => $history->getCollection()->map(fn ($j) => $this->presentJob($j))->values(),
            'meta' => ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'total' => $history->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function syncShow(int $job): JsonResponse
    {
        $job = GuideSyncJob::with('user:id,name')->findOrFail($job); // dicari setelah gate (non-pengelola → 403, bukan 404)

        return $this->json($this->presentJob($job) + ['scope' => $job->scope]);
    }

    public function syncNow(Request $request): JsonResponse
    {
        if (GuideSyncJob::whereIn('status', ['queued', 'running'])->exists()) {
            return response()->json(['message' => 'Sinkronisasi panduan sedang mengantre atau berjalan.'], 409);
        }
        $job = SyncGuides::enqueue('manual', 'Diantre lewat API oleh '.$request->user('web')->name);
        AuditLog::record('guide.sync_queued', 'panduan', "Sinkronisasi panduan diantre (#{$job->id})", null, ['sync_job_id' => $job->id], action: 'diubah');

        return response()->json(['data' => $this->presentJob($job->fresh())], 202)->header('Cache-Control', 'no-store');
    }

    public function staleIndex(GuideGenerator $generator): JsonResponse
    {
        $rows = GuideEntry::where('is_stale', true)->with('overrides:id,guide_entry_id,field')->orderBy('stale_at')->orderBy('position')->get();
        $plan = $rows->isNotEmpty() ? $generator->plan() : ['updated' => []];
        $drafts = GuideDraft::where('status', 'pending')->whereIn('key', $rows->pluck('key'))->pluck('id', 'key');

        return $this->json([
            'total' => $rows->count(),
            'guides' => $rows->map(fn (GuideEntry $e) => [
                'key' => $e->key, 'title' => $e->title, 'menu' => $e->menu_key, 'source' => $e->source,
                'reason' => $e->stale_reason, 'stale_at' => $e->stale_at?->toIso8601String(), 'generated_at' => $e->generated_at?->toIso8601String(),
                'overridden' => $e->overrides->pluck('field')->values(), 'draft_changes' => $plan['updated'][$e->key] ?? [], 'pending_draft_id' => $drafts[$e->key] ?? null,
            ])->values(),
        ]);
    }

    public function staleMark(Request $request, string $key, GuideService $guides): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], ['alasan.required' => 'Tulis alasan panduan perlu ditinjau.']);
        $entry = GuideEntry::where('key', $key)->firstOrFail();
        $reason = trim(preg_replace('/\s+/u', ' ', $data['alasan']));
        if ($entry->is_stale && $entry->stale_reason === $reason) {
            return response()->json(['message' => 'Panduan sudah ditandai dengan alasan yang sama.'], 409);
        }
        $guides->markStale($entry, $reason, $request->user('web'));

        return $this->json($this->presentStale($entry->fresh()));
    }

    public function staleClear(Request $request, string $key, GuideService $guides): JsonResponse
    {
        $data = $request->validate(['catatan' => ['nullable', 'string', 'max:200']]);
        $entry = GuideEntry::where('key', $key)->firstOrFail();
        if (! $guides->markReviewed($entry, $request->user('web'), $data['catatan'] ?? null)) {
            return response()->json(['message' => 'Panduan ini tidak sedang ditandai usang.'], 409);
        }

        return $this->json($this->presentStale($entry->fresh()));
    }

    public function staleClearAll(Request $request, GuideService $guides): JsonResponse
    {
        $data = $request->validate(['catatan' => ['nullable', 'string', 'max:200']]);
        $keys = [];
        foreach (GuideEntry::where('is_stale', true)->orderBy('position')->orderBy('id')->get() as $entry) {
            if ($guides->markReviewed($entry, $request->user('web'), $data['catatan'] ?? 'Ditinjau bersamaan')) {
                $keys[] = $entry->key;
            }
        }

        return $this->json(['reviewed' => $keys, 'count' => count($keys)]);
    }

    private function presentStale(GuideEntry $e): array
    {
        return ['key' => $e->key, 'title' => $e->title, 'is_stale' => $e->is_stale, 'reason' => $e->stale_reason, 'stale_at' => $e->stale_at?->toIso8601String()];
    }

    private function presentJob(GuideSyncJob $j): array
    {
        return [
            'id' => $j->id, 'status' => $j->status, 'status_label' => GuideSyncJob::STATUSES[$j->status] ?? $j->status,
            'trigger' => $j->trigger, 'trigger_label' => GuideSyncJob::TRIGGERS[$j->trigger] ?? $j->trigger,
            'triggered_by' => $j->relationLoaded('user') ? $j->user?->name : null, 'stats' => $j->stats, 'note' => $j->note, 'error' => $j->error,
            'queued_at' => $j->created_at?->toIso8601String(), 'started_at' => $j->started_at?->toIso8601String(),
            'finished_at' => $j->finished_at?->toIso8601String(), 'duration_seconds' => $j->durationSeconds(),
        ];
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
