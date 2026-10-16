<?php

namespace App\Services;

use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use Illuminate\Support\Collection;

/**
 * Bacaan riwayat versi panduan (dipakai halaman Riwayat versi & API): versi aktif tiap panduan (sidik jari isi efektif
 * sekarang = sidik jari versi, satu kueri untuk banyak panduan) dan bagian yang berubah dibanding versi sebelumnya
 * (cuplikan pendahulu dimuat sekaligus).
 */
class GuideVersionQuery
{
    public const CHANGE_LABELS = GuideOverride::FIELDS + ['overridden' => 'Penanda suntingan'];

    public function __construct(private GuideService $guides) {}

    /**
     * @param  list<int>  $entryIds
     * @return array<int, ?int> entry_id → nomor versi aktif (null bila isi sekarang tidak sama dengan versi mana pun)
     */
    public function active(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $current = GuideEntry::with('overrides')->whereKey($entryIds)->get()->mapWithKeys(fn ($e) => [$e->id => GuideVersion::hashOf($this->guides->snapshot($e))]);
        $matches = GuideVersion::whereIn('guide_entry_id', $entryIds)->whereIn('content_hash', $current->values()->unique())
            ->selectRaw('guide_entry_id, content_hash, max(version) as version')->groupBy('guide_entry_id', 'content_hash')->get();
        $out = array_fill_keys($entryIds, null);
        foreach ($matches as $m) {
            if (($current[$m->guide_entry_id] ?? null) === $m->content_hash) {
                $out[$m->guide_entry_id] = (int) $m->version;
            }
        }

        return $out;
    }

    /**
     * Bagian yang berubah tiap versi dibanding pendahulunya.
     *
     * @param  Collection<int, GuideVersion>  $versions
     * @return array<string, ?list<string>> "entry_id:version" → kunci bagian (null = versi pertama/pendahulu tiada)
     */
    public function changedFields(Collection $versions): array
    {
        if ($versions->isEmpty()) {
            return [];
        }
        $previous = GuideVersion::query()->whereIn('guide_entry_id', $versions->pluck('guide_entry_id')->unique())
            ->whereIn('version', $versions->pluck('version')->map(fn ($n) => $n - 1)->filter()->unique())
            ->get(['guide_entry_id', 'version', 'snapshot'])
            ->mapWithKeys(fn ($v) => [$v->guide_entry_id.':'.($v->version + 1) => $v->snapshot]);
        $out = [];
        foreach ($versions as $v) {
            $before = $previous[$v->guide_entry_id.':'.$v->version] ?? null;
            $out[$v->guide_entry_id.':'.$v->version] = $before === null ? null
                : array_values(array_filter(array_keys(self::CHANGE_LABELS), fn ($f) => ($before[$f] ?? null) != ($v->snapshot[$f] ?? null)));
        }

        return $out;
    }

    /** Bentuk satu versi untuk API. */
    public function present(GuideVersion $v, ?int $active, ?array $changed, bool $withSnapshot = false): array
    {
        return [
            'version' => $v->version, 'type' => $v->change_type, 'type_label' => GuideVersion::TYPES[$v->change_type] ?? $v->change_type,
            'note' => $v->change_note, 'by' => $v->author?->name, 'at' => $v->created_at?->toIso8601String(),
            'sync_job_id' => $v->sync_job_id, 'restored_from_version' => $v->restored_from_version, 'content_hash' => $v->content_hash,
            'active' => $active === $v->version, 'first' => $changed === null,
            'changed' => $changed ?? [], 'changed_labels' => array_map(fn ($f) => self::CHANGE_LABELS[$f], $changed ?? []),
        ] + ($withSnapshot ? ['snapshot' => $v->snapshot] : ['title' => $v->snapshot['title'] ?? null]);
    }
}
