<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Services\GuideVersionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar versi panduan (gate lihat-riwayat-panduan: pengelola panduan & pemegang menu Audit, baca saja):
 * - GET /panduan/versi/data?jenis=&per=                 → versi terbaru semua panduan (dengan penanda aktif);
 * - GET /panduan/versi/data/{key}?jenis=&per=           → versi satu panduan/menu dari waktu ke waktu: nomor, jenis, catatan,
 *   pengubah, waktu, sinkronisasi, sumber pemulihan, aktif, bagian yang berubah dibanding versi sebelumnya + jumlah per jenis;
 * - GET /panduan/versi/data/{key}/{version}             → satu versi lengkap dengan cuplikan isi.
 */
class GuideVersionController extends Controller
{
    public function index(Request $request, GuideVersionQuery $query): JsonResponse
    {
        $f = $this->filters($request);
        $page = GuideVersion::with(['author:id,name', 'entry:id,key,title'])
            ->when($f['jenis'] ?? null, fn ($q, $t) => $q->where('change_type', $t))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($f['per'] ?? 20));

        return $this->paged($page, $query, true);
    }

    public function forGuide(Request $request, string $key, GuideVersionQuery $query): JsonResponse
    {
        $f = $this->filters($request);
        $entry = $this->entry($key);
        $page = GuideVersion::with('author:id,name')->where('guide_entry_id', $entry->id)
            ->when($f['jenis'] ?? null, fn ($q, $t) => $q->where('change_type', $t))
            ->orderByDesc('version')->paginate((int) ($f['per'] ?? 20));
        $counts = GuideVersion::where('guide_entry_id', $entry->id)->selectRaw('change_type, count(*) as n')->groupBy('change_type')->pluck('n', 'change_type');
        $active = $query->active([$entry->id])[$entry->id];

        return $this->paged($page, $query, false, [
            'guide' => ['key' => $entry->key, 'title' => $entry->title, 'menu' => $entry->menu_key, 'source' => $entry->source],
            'active_version' => $active, 'latest_version' => (int) GuideVersion::where('guide_entry_id', $entry->id)->max('version'),
            'type_counts' => array_map(fn ($t) => (int) ($counts[$t] ?? 0), array_combine(array_keys(GuideVersion::TYPES), array_keys(GuideVersion::TYPES))),
        ]);
    }

    public function show(string $key, int $version, GuideVersionQuery $query): JsonResponse
    {
        $entry = $this->entry($key);
        $v = GuideVersion::with('author:id,name')->where('guide_entry_id', $entry->id)->where('version', $version)->firstOrFail();
        $changed = $query->changedFields(collect([$v]))[$entry->id.':'.$v->version] ?? null;

        return response()->json(['data' => $query->present($v, $query->active([$entry->id])[$entry->id], $changed, true)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function paged($page, GuideVersionQuery $query, bool $withGuide, array $meta = []): JsonResponse
    {
        $items = $page->getCollection();
        $active = $query->active($items->pluck('guide_entry_id')->unique()->values()->all());
        $changed = $query->changedFields($items);
        $data = $items->map(fn (GuideVersion $v) => $query->present($v, $active[$v->guide_entry_id] ?? null, $changed[$v->guide_entry_id.':'.$v->version] ?? null)
            + ($withGuide ? ['guide' => ['key' => $v->entry?->key, 'title' => $v->entry?->title]] : []))->values();

        return response()->json(['data' => $data, 'meta' => $meta + ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]])
            ->header('Cache-Control', 'private, no-store');
    }

    private function filters(Request $request): array
    {
        return $request->validate(['jenis' => ['nullable', 'in:'.implode(',', array_keys(GuideVersion::TYPES))], 'per' => ['nullable', 'integer', 'min:1', 'max:100']]);
    }

    private function entry(string $key): GuideEntry
    {
        return GuideEntry::where('key', $key)->firstOrFail();
    }
}
