<?php

namespace App\Services;

use App\Models\GuideEntry;
use App\Models\GuideSyncJob;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessPreview;
use App\Support\GuideCatalog;
use App\Support\Menu;
use Illuminate\Support\Facades\DB;

/**
 * Penyusun panduan otomatis dari konfigurasi sistem: daftar menu (Menu::ITEMS: label, deskripsi, rute), halaman per
 * menu beserta judulnya (dibaca dari rute & middleware menu:/peran: lewat AccessPreview::pages) dan peran pemegang
 * menu (roles.permissions). Menu yang punya isi kurasi di GuideCatalog memakai isi itu lalu dilengkapi daftar halaman
 * yang terbaca; menu baru tanpa isi kurasi tetap mendapat draf (ringkasan dari deskripsi menu, langkah per halaman).
 * sync() menyimpan ke guide_entries (sumber auto), mencatat versi bila isi berubah, dan TIDAK menimpa panduan manual.
 */
class GuideGenerator
{
    public const MAX_PAGES = 8;

    public function __construct(private GuideService $guides) {}

    /**
     * Fakta menu yang terbaca dari sistem.
     *
     * Halaman berparameter (mis. /barang/{product}) tidak dijadikan langkah; jumlahnya dicatat di skipped_pages.
     *
     * @return array<string, array{label: string, description: string, route: ?string, group: string, roles: list<string>, pages: list<array{name: string, title: string, uri: string, roles: list<string>, ability: ?string}>, skipped_pages: int}>
     */
    public function scan(): array
    {
        $pages = collect((new AccessPreview)->pages())->groupBy('menu');
        $grants = $this->guides->grantMap(); // satu kueri peran untuk semua menu
        $facts = [];
        foreach (Menu::ITEMS as $key => $item) {
            $mine = $pages[$key] ?? collect();
            $facts[$key] = [
                'label' => $item['label'], 'description' => $item['description'], 'route' => $item['route'],
                'group' => GuideCatalog::groupFor($key), 'roles' => $grants[$key] ?? [],
                'pages' => $mine->reject(fn ($p) => str_contains($p['uri'], '{'))
                    ->map(fn ($p) => ['name' => $p['name'], 'title' => $p['title'], 'uri' => $p['uri'], 'roles' => $p['roles'], 'ability' => $p['ability']])->values()->all(),
                'skipped_pages' => $mine->filter(fn ($p) => str_contains($p['uri'], '{'))->count(),
            ];
        }

        return $facts;
    }

    /**
     * Draf panduan untuk semua menu petugas + panduan umum & anggota dari katalog.
     *
     * @return array<string, array{menu: ?string, audience: string, title: string, summary: string, steps: list<array>, tips: list<string>, related: array<string, string>, keywords: ?string, required_permissions: list<string>}>
     */
    public function drafts(): array
    {
        $catalog = GuideCatalog::entries();
        $drafts = [];
        foreach ($this->scan() as $key => $f) {
            $base = $catalog[$key] ?? null;
            $related = $base['related'] ?? [];
            foreach (array_slice($f['pages'], 0, self::MAX_PAGES) as $p) { // halaman yang terbaca dari rute
                if ($p['name'] !== $f['route'] && ! isset($related[$p['name']])) {
                    $related[$p['name']] = $p['title'];
                }
            }
            $drafts[$key] = [
                'menu' => $key, 'audience' => 'petugas',
                'title' => $base['title'] ?? 'Panduan menu '.$f['label'],
                'summary' => $base['summary'] ?? $this->summaryFor($f),
                'steps' => $base['steps'] ?? $this->stepsFor($f),
                'tips' => $base['tips'] ?? [],
                'related' => $related,
                'keywords' => GuideCatalog::KEYWORDS[$key] ?? mb_strtolower($f['label'].' '.$f['description']),
                'required_permissions' => [$key],
            ];
        }
        foreach ($catalog as $key => $e) { // panduan umum (akun) & anggota tidak bergantung menu
            if ($e['menu'] === null) {
                $drafts[$key] = $e + ['keywords' => GuideCatalog::KEYWORDS[$key] ?? null, 'required_permissions' => []];
            }
        }

        return $drafts;
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
                if (! $entry) {
                    $entry = GuideEntry::create(['key' => $key, 'source' => 'auto', 'generated_at' => now()] + $values);
                    $stats['created'][] = $key;
                } else {
                    $entry->fill($values);
                    if (! $entry->isDirty()) {
                        $stats['unchanged'][] = $key;

                        continue;
                    }
                    $entry->fill(['generated_at' => now(), 'is_stale' => false, 'stale_reason' => null, 'stale_at' => null])->save();
                    $stats['updated'][] = $key;
                }
                $this->guides->recordVersion($entry, 'generate', $note, $by, $job?->id);
            }
        });

        return $stats;
    }

    private function summaryFor(array $f): string
    {
        $roles = collect($f['roles'])->map(fn ($r) => Role::LABELS[$r] ?? $r)->join(', ', ' dan ');

        return rtrim($f['description'], '.').'.'.($roles !== '' ? " Menu ini dapat dibuka peran {$roles}." : '');
    }

    /** @return list<array{text: string, route?: string}> */
    private function stepsFor(array $f): array
    {
        $steps = [array_filter(['text' => "Buka menu {$f['label']} di menu samping.", 'route' => $f['route']])];
        foreach (array_slice($f['pages'], 0, self::MAX_PAGES - 1) as $p) {
            if ($p['name'] === $f['route']) {
                continue;
            }
            $only = $p['roles'] ? ' (khusus '.collect($p['roles'])->map(fn ($r) => Role::LABELS[$r] ?? $r)->join(' / ').')' : '';
            $steps[] = ['text' => "Buka halaman {$p['title']}{$only}.", 'route' => $p['name']];
        }
        if (count($steps) === 1) {
            $steps[] = ['text' => 'Ikuti petunjuk di halaman tersebut; perubahan penting tercatat di Audit.'];
        }

        return $steps;
    }
}
