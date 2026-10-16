<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Models\GuideDraft;
use App\Models\GuideEntry;
use App\Services\GuideDraftService;
use App\Services\GuideGenerator;
use App\Services\GuideMetadataService;
use App\Services\GuideScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hasil pemindaian & draf panduan untuk pengelola panduan (gate kelola-panduan), tanpa menyimpan apa pun:
 * - GET /panduan/pembaruan/pemindaian       → fakta per menu (halaman, peran), izin per peran, temuan konfigurasi,
 *   ringkasan, dan perubahan sejak pemindaian tersimpan terakhir (menu baru/hilang, aspek berubah);
 * - GET /panduan/pembaruan/draf             → rencana penyusunan ulang (baru, diperbarui + kolom, tetap, manual,
 *   menu yang sudah tiada) dan draf yang menunggu tinjauan (guide_drafts);
 * - GET /panduan/pembaruan/draf/{key}       → pratinjau satu draf dibanding isi tersimpan per bagian.
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

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
