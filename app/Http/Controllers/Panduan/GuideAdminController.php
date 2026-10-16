<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Services\GuideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Edit manual panduan oleh Admin (gate kelola-panduan) — lapisan override di atas isi otomatis:
 * - GET    /panduan/kelola/{key}          → isi otomatis, isi efektif, suntingan per bagian (penyunting & waktu), versi;
 * - PUT    /panduan/kelola/{key}/{field}  → {value, catatan?} simpan suntingan bagian title|summary|steps|tips;
 * - DELETE /panduan/kelola/{key}/{field}  → {catatan?} kembalikan bagian ke hasil otomatis.
 * Setiap perubahan menulis versi (guide_versions) & jejak audit ber-HMAC (kategori Panduan).
 */
class GuideAdminController extends Controller
{
    public function show(string $key, GuideService $guides): JsonResponse
    {
        $entry = $this->entry($key);

        return response()->json(['data' => $this->present($entry, $guides)]);
    }

    public function update(Request $request, string $key, string $field, GuideService $guides): JsonResponse
    {
        $entry = $this->entry($key);
        $data = $request->validate(['value' => ['present'], 'catatan' => ['nullable', 'string', 'max:200']]);
        $guides->setOverride($entry, $field, $data['value'], $request->user('web'), $data['catatan'] ?? null);

        return response()->json(['data' => $this->present($entry->fresh(), $guides)]);
    }

    public function destroy(Request $request, string $key, string $field, GuideService $guides): JsonResponse
    {
        $entry = $this->entry($key);
        $data = $request->validate(['catatan' => ['nullable', 'string', 'max:200']]);
        $reverted = $guides->clearOverride($entry, $field, $request->user('web'), $data['catatan'] ?? null);

        return response()->json(['data' => $this->present($entry->fresh(), $guides), 'reverted' => $reverted]);
    }

    private function entry(string $key): GuideEntry
    {
        return GuideEntry::where('key', $key)->firstOrFail();
    }

    private function present(GuideEntry $entry, GuideService $guides): array
    {
        $entry->load('overrides.editor');

        return [
            'key' => $entry->key, 'audience' => $entry->audience, 'menu' => $entry->menu_key, 'source' => $entry->source,
            'is_stale' => $entry->is_stale, 'generated_at' => $entry->generated_at?->toIso8601String(),
            'auto' => array_intersect_key($entry->toGuide(), GuideOverride::FIELDS),
            'effective' => array_intersect_key($guides->effective($entry), GuideOverride::FIELDS),
            'overrides' => $entry->overrides->mapWithKeys(fn (GuideOverride $o) => [$o->field => [
                'value' => $o->value, 'updated_by' => $o->editor?->name, 'updated_at' => $o->updated_at?->toIso8601String(),
            ]])->all(),
            'versions' => $entry->versions()->with('author:id,name')->limit(50)->get()->map(fn (GuideVersion $v) => [
                'version' => $v->version, 'type' => $v->change_type, 'type_label' => GuideVersion::TYPES[$v->change_type] ?? $v->change_type,
                'note' => $v->change_note, 'by' => $v->author?->name, 'at' => $v->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
