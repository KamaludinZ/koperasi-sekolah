<?php

namespace App\Services;

use App\Models\GuideEntry;
use App\Models\GuideSyncJob;
use App\Models\User;
use App\Support\GuideCatalog;
use App\Support\Menu;
use Illuminate\Support\Facades\DB;

/**
 * Penyusun panduan otomatis dari konfigurasi sistem yang dibaca GuideScanner: daftar menu (Menu::ITEMS: label,
 * deskripsi, rute), halaman per menu beserta judulnya (rute & middleware menu:/peran:/can:) dan peran pemegang menu
 * (roles.permissions). Menu yang punya isi kurasi di GuideCatalog memakai isi itu lalu dilengkapi daftar halaman
 * yang terbaca; menu baru tanpa isi kurasi tetap mendapat draf (ringkasan dari deskripsi menu, langkah per halaman).
 * sync() menyimpan ke guide_entries (sumber auto), mencatat versi bila isi berubah, dan TIDAK menimpa panduan manual.
 */
class GuideGenerator
{
    public function __construct(private GuideService $guides) {}

    /** Fakta menu yang terbaca dari sistem (lihat GuideScanner::scan). */
    public function scan(): array
    {
        return app(GuideScanner::class)->scan();
    }

    /**
     * Draf panduan untuk semua menu petugas + panduan umum & anggota dari katalog.
     *
     * @return array<string, array{menu: ?string, audience: string, title: string, summary: string, steps: list<array>, tips: list<string>, related: array<string, string>, keywords: ?string, required_permissions: list<string>}>
     */
    public function drafts(): array
    {
        $catalog = GuideCatalog::entries();
        $drafter = app(GuideDrafter::class);
        $drafts = [];
        foreach ($this->scan() as $key => $f) {
            $drafts[$key] = $drafter->draft($key, $f, $catalog[$key] ?? null);
        }

        return $drafts + $drafter->general();
    }

    /**
     * Pratinjau satu draf dibanding isi otomatis tersimpan: perbedaan per bagian (teks berubah; langkah/tips/halaman
     * terkait ditambah/dibuang), bagian yang sedang disunting admin (suntingan tetap dipakai), dan status manual.
     *
     * @return ?array{key: string, draft: array, stored: ?array, manual: bool, overridden: list<string>, diff: array<string, array{changed: bool, added: list<string>, removed: list<string>}>}
     */
    public function preview(string $key): ?array
    {
        $drafts = $this->drafts();
        if (! isset($drafts[$key])) {
            return null;
        }
        $draft = $drafts[$key];
        $entry = GuideEntry::where('key', $key)->with('overrides')->first();
        $stored = $entry?->toGuide();
        $items = fn (string $field, ?array $g) => match ($field) {
            'steps' => array_map(fn ($s) => $s['text'].(isset($s['route']) ? " → {$s['route']}" : ''), $g['steps'] ?? []),
            'tips' => array_values($g['tips'] ?? []),
            'related' => array_map(fn ($r, $l) => "{$l} ({$r})", array_keys($g['related'] ?? []), $g['related'] ?? []),
            default => [(string) ($g[$field] ?? '')],
        };
        $diff = [];
        foreach (['title', 'summary', 'steps', 'tips', 'related'] as $field) {
            $new = $items($field, $draft);
            $old = $stored ? $items($field, $stored) : [];
            $diff[$field] = ['changed' => $new !== $old, 'added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))];
        }

        return [
            'key' => $key, 'draft' => $draft, 'stored' => $stored, 'manual' => $entry?->source === 'manual',
            'overridden' => $entry ? $entry->overrides->pluck('field')->values()->all() : [], 'diff' => $diff,
        ];
    }

    /** Nilai kolom guide_entries dari satu draf. */
    private function values(array $d, int $pos): array
    {
        return [
            'menu_key' => $d['menu'], 'audience' => $d['audience'], 'title' => $d['title'], 'summary' => $d['summary'],
            'steps' => $d['steps'], 'tips' => $d['tips'], 'related' => $d['related'], 'keywords' => $d['keywords'],
            'required_permissions' => $d['required_permissions'], 'position' => $pos,
        ];
    }

    /**
     * Pratinjau tanpa menyimpan: panduan yang akan dibuat, diperbarui (beserta kolom yang berubah), tetap, dilewati
     * karena manual, dan panduan otomatis yang menunya sudah tidak ada lagi di sistem.
     *
     * @return array{created: list<string>, updated: array<string, list<string>>, unchanged: list<string>, skipped_manual: list<string>, orphaned: list<string>}
     */
    public function plan(): array
    {
        $plan = ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped_manual' => [], 'orphaned' => []];
        $drafts = $this->drafts();
        $entries = GuideEntry::all()->keyBy('key');
        $pos = 0;
        foreach ($drafts as $key => $d) {
            $pos++;
            $entry = $entries[$key] ?? null;
            if (! $entry) {
                $plan['created'][] = $key;
            } elseif ($entry->source === 'manual') {
                $plan['skipped_manual'][] = $key;
            } else {
                $entry->fill($this->values($d, $pos));
                $dirty = array_keys($entry->getDirty());
                $dirty === [] ? $plan['unchanged'][] = $key : $plan['updated'][$key] = $dirty;
            }
        }
        $plan['orphaned'] = $entries->filter(fn ($e) => $e->source === 'auto' && ! isset($drafts[$e->key]))->keys()->values()->all();

        return $plan;
    }

    /**
     * Simpan draf ke guide_entries: buat yang baru, perbarui yang otomatis bila berubah, lewati yang manual.
     * $only membatasi ke kunci panduan tertentu (mis. menerapkan satu draf dari pratinjau).
     *
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>, skipped_manual: list<string>}
     */
    public function sync(?User $by = null, string $note = 'Disusun otomatis dari konfigurasi menu', ?array $only = null, ?GuideSyncJob $job = null): array
    {
        $stats = ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped_manual' => [], 'drafted' => []];
        $pos = 0;
        DB::transaction(function () use (&$stats, &$pos, $by, $note, $only, $job) {
            foreach ($this->drafts() as $key => $d) {
                $pos++;
                if ($only !== null && ! in_array($key, $only, true)) {
                    continue; // hanya panduan tertentu (posisi tetap dihitung dari urutan lengkap)
                }
                $entry = GuideEntry::where('key', $key)->lockForUpdate()->first();
                $values = $this->values($d, $pos);
                if ($entry && $entry->source === 'manual') {
                    $stats['skipped_manual'][] = $key;
                    // isi otomatis berbeda dari panduan manual → simpan sebagai draf untuk ditinjau, bukan menimpa
                    $probe = (clone $entry)->fill(array_intersect_key($values, array_flip(GuideDraftService::CONTENT)));
                    if ($changed = array_keys($probe->getDirty())) {
                        app(GuideDraftService::class)->stage($key, $d, $changed, 'Panduan manual: isi otomatis terbaru berbeda', $job);
                        $stats['drafted'][] = $key;
                    }

                    continue;
                }
                $fieldChanges = [];
                if (! $entry) {
                    $entry = GuideEntry::create(['key' => $key, 'source' => 'auto', 'generated_at' => now()] + $values);
                    $stats['created'][] = $key;
                } else {
                    $entry->fill($values);
                    if (! $entry->isDirty()) {
                        $stats['unchanged'][] = $key;

                        continue;
                    }
                    foreach (array_intersect(array_keys($entry->getDirty()), GuideDraftService::CONTENT) as $f) {
                        $fieldChanges[$f] = [$entry->getOriginal($f), $entry->{$f}];
                    }
                    $entry->fill(['generated_at' => now(), 'is_stale' => false, 'stale_reason' => null, 'stale_at' => null])->save();
                    $stats['updated'][] = $key;
                }
                $version = $this->guides->recordVersion($entry, 'generate', $note, $by, $job?->id);
                if (in_array($key, $stats['created'], true)) {
                    $this->guides->logChange($entry, 'created', null, null, null, $note, $by, $version, $job?->id);
                }
                foreach ($fieldChanges as $f => [$old, $new]) {
                    $this->guides->logChange($entry, 'generate', $f, $old, $new, $note, $by, $version, $job?->id);
                }
            }
        });

        return $stats;
    }
}
