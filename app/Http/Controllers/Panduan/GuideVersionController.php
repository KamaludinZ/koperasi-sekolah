<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Services\GuideOverrideService;
use App\Services\GuideService;
use App\Services\GuideVersionQuery;
use App\Support\GuideVersionDiff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Daftar versi panduan (gate lihat-riwayat-panduan: pengelola panduan & pemegang menu Audit, baca saja):
 * - GET /panduan/versi/data?jenis=&per=                 → versi terbaru semua panduan (dengan penanda aktif);
 * - GET /panduan/versi/data/{key}?jenis=&per=           → versi satu panduan/menu dari waktu ke waktu: nomor, jenis, catatan,
 *   pengubah, waktu, sinkronisasi, sumber pemulihan, aktif, bagian yang berubah dibanding versi sebelumnya + jumlah per jenis;
 * - GET /panduan/versi/data/{key}/{version}             → satu versi lengkap dengan cuplikan isi;
 * - GET /panduan/versi/data/{key}/banding?dari=&ke=      → perbandingan isi dua versi per bagian (ke=sekarang → isi yang
 *   sedang tampil): judul/ringkasan lama & baru, langkah/tips sebagai beda baris berurutan, perubahan penanda suntingan;
 * - POST /panduan/versi/data/{key}/{version}/pulihkan {alasan, sidik?} → (pengelola panduan) pulihkan isi ke versi itu:
 *   versi baru "restore", audit & riwayat; sidik = current_hash dari daftar versi (409 bila isi sudah berubah).
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
            'current_hash' => GuideVersion::hashOf(app(GuideService::class)->snapshot($entry->load('overrides'))),
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

    public function compare(Request $request, string $key, GuideService $guides): JsonResponse
    {
        $entry = $this->entry($key);
        $f = $request->validate(['dari' => ['required', 'integer', 'min:1'], 'ke' => ['required', 'regex:/^(\d+|sekarang)$/']]);
        $side = function (string|int $ref) use ($entry, $guides) {
            if ($ref === 'sekarang') {
                return ['version' => null, 'label' => 'Isi sekarang', 'snapshot' => $guides->snapshot($entry->load('overrides')), 'type' => null, 'by' => null, 'at' => null];
            }
            $v = GuideVersion::with('author:id,name')->where('guide_entry_id', $entry->id)->where('version', (int) $ref)->first();
            abort_if($v === null, 404, "Versi {$ref} tidak ditemukan pada panduan ini.");

            return ['version' => $v->version, 'label' => "Versi {$v->version}", 'snapshot' => $v->snapshot, 'type' => $v->change_type, 'by' => $v->author?->name, 'at' => $v->created_at?->toIso8601String()];
        };
        $a = $side((int) $f['dari']);
        $b = $side($f['ke'] === 'sekarang' ? 'sekarang' : (int) $f['ke']);
        $diff = GuideVersionDiff::compare($a['snapshot'], $b['snapshot']);
        unset($a['snapshot'], $b['snapshot']);

        return response()->json(['data' => [
            'guide' => ['key' => $entry->key, 'title' => $entry->title], 'from' => $a, 'to' => $b,
            'identical' => ! $diff['changed'] && ! $diff['overridden']['added'] && ! $diff['overridden']['removed'],
        ] + $diff])->header('Cache-Control', 'private, no-store');
    }

    public function restore(Request $request, string $key, int $version, GuideOverrideService $overrides, GuideVersionQuery $query): JsonResponse
    {
        $entry = $this->entry($key);
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200'], 'sidik' => ['nullable', 'string', 'size:64']],
            ['alasan.required' => 'Tulis alasan pemulihan.']);
        $target = GuideVersion::where('guide_entry_id', $entry->id)->where('version', $version)->firstOrFail();
        try {
            $r = $overrides->restore($entry, $target, $request->user('web'), $data['alasan'], $data['sidik'] ?? null);
        } catch (ValidationException $e) {
            $conflict = array_key_exists('sidik', $e->errors());

            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], $conflict ? 409 : 422);
        }

        return response()->json(['data' => [
            'restored_from' => $target->version, 'new_version' => $r['version']?->version, 'changed' => $r['changed'],
            'message' => $r['version'] ? "Panduan dipulihkan ke isi versi {$target->version} (versi {$r['version']->version})." : 'Isi panduan sudah sama dengan versi itu.',
            'active_version' => $query->active([$entry->id])[$entry->id],
        ]])->header('Cache-Control', 'no-store');
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
