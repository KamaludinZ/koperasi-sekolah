<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Models\GuideChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Riwayat perubahan panduan untuk audit (gate lihat-riwayat-panduan: pengelola panduan & pemegang menu Audit), baca saja:
 * - GET /panduan/riwayat?panduan=&aksi=&bagian=&oleh=&dari=&sampai=&per=  → daftar berhalaman, terbaru dulu;
 * - GET /panduan/riwayat/{id}                                           → rincian (isi sebelum & sesudah utuh);
 * - GET /panduan/riwayat/ekspor (saringan sama)                         → CSV (≤ 5.000 baris, aman dari formula).
 */
class GuideHistoryController extends Controller
{
    public const EXPORT_LIMIT = 5000;

    public function index(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $page = $this->query($f)->paginate((int) ($f['per'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (GuideChange $c) => $this->present($c, false))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(int $change): JsonResponse
    {
        $c = GuideChange::with(['entry:id,key,title', 'author:id,name', 'version:id,version'])->findOrFail($change); // dicari setelah gate

        return response()->json(['data' => $this->present($c, true)])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->query($this->filters($request))->limit(self::EXPORT_LIMIT)->get();
        $cell = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v; // cegah formula CSV
        $flat = fn ($v) => implode(' | ', GuideChange::lines($v));

        return response()->streamDownload(function () use ($rows, $cell, $flat) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Waktu', 'Panduan', 'Kunci', 'Bagian', 'Perubahan', 'Oleh', 'Versi', 'Sebelum', 'Sesudah', 'Catatan'], ';');
            foreach ($rows as $c) {
                fputcsv($out, array_map($cell, [
                    $c->created_at?->format('Y-m-d H:i:s'), $c->entry?->title, $c->entry?->key, $c->field ? (GuideChange::FIELD_LABELS[$c->field] ?? $c->field) : '',
                    GuideChange::ACTIONS[$c->action] ?? $c->action, $c->author?->name ?? 'Sistem', $c->version?->version,
                    $flat($c->before), $flat($c->after), $c->note,
                ]), ';');
            }
            fclose($out);
        }, 'riwayat-panduan-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'panduan' => ['nullable', 'string', 'regex:/^[a-z0-9._-]{1,60}$/'],
            'aksi' => ['nullable', 'in:'.implode(',', array_keys(GuideChange::ACTIONS))],
            'bagian' => ['nullable', 'in:'.implode(',', array_keys(GuideChange::FIELD_LABELS))],
            'oleh' => ['nullable', 'integer', 'min:1'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'per' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function query(array $f): Builder
    {
        return GuideChange::query()->with(['entry:id,key,title', 'author:id,name', 'version:id,version'])
            ->when($f['panduan'] ?? null, fn ($q, $k) => $q->whereHas('entry', fn ($e) => $e->where('key', $k)))
            ->when($f['aksi'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($f['bagian'] ?? null, fn ($q, $b) => $q->where('field', $b))
            ->when($f['oleh'] ?? null, fn ($q, $u) => $q->where('changed_by', $u))
            ->when($f['dari'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($f['sampai'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('id');
    }

    private function present(GuideChange $c, bool $full): array
    {
        $before = GuideChange::lines($c->before);
        $after = GuideChange::lines($c->after);

        return [
            'id' => $c->id, 'at' => $c->created_at?->toIso8601String(),
            'guide' => ['key' => $c->entry?->key, 'title' => $c->entry?->title],
            'field' => $c->field, 'field_label' => $c->field ? (GuideChange::FIELD_LABELS[$c->field] ?? $c->field) : null,
            'action' => $c->action, 'action_label' => GuideChange::ACTIONS[$c->action] ?? $c->action,
            'by' => $c->author?->name, 'by_id' => $c->changed_by, 'version' => $c->version?->version, 'sync_job_id' => $c->sync_job_id, 'note' => $c->note,
        ] + ($full
            ? ['before' => $c->before, 'after' => $c->after, 'before_lines' => $before, 'after_lines' => $after]
            : ['before_preview' => mb_strimwidth(implode(' | ', $before), 0, 160, '…'), 'after_preview' => mb_strimwidth(implode(' | ', $after), 0, 160, '…')]);
    }
}
