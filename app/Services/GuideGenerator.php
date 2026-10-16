<?php

namespace App\Services;

use App\Models\GuideEntry;
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
     * @return array<string, array{label: string, description: string, route: ?string, group: string, roles: list<string>, pages: list<array{name: string, title: string, uri: string, roles: list<string>}>}>
     */
    public function scan(): array
    {
        $pages = collect((new AccessPreview)->pages())->groupBy('menu');
        $facts = [];
        foreach (Menu::ITEMS as $key => $item) {
            $facts[$key] = [
                'label' => $item['label'], 'description' => $item['description'], 'route' => $item['route'],
                'group' => GuideCatalog::groupFor($key), 'roles' => Menu::grantingRoles($key),
                'pages' => ($pages[$key] ?? collect())->reject(fn ($p) => str_contains($p['uri'], '{'))
                    ->map(fn ($p) => ['name' => $p['name'], 'title' => $p['title'], 'uri' => $p['uri'], 'roles' => $p['roles']])->values()->all(),
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
     * Simpan draf ke guide_entries: buat yang baru, perbarui yang otomatis bila berubah, lewati yang manual.
     *
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>, skipped_manual: list<string>}
     */
    public function sync(?User $by = null, string $note = 'Disusun otomatis dari konfigurasi menu'): array
    {
        $stats = ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped_manual' => []];
        $pos = 0;
        DB::transaction(function () use (&$stats, &$pos, $by, $note) {
            foreach ($this->drafts() as $key => $d) {
                $pos++;
                $entry = GuideEntry::where('key', $key)->lockForUpdate()->first();
                if ($entry && $entry->source === 'manual') {
                    $stats['skipped_manual'][] = $key;

                    continue;
                }
                $values = [
                    'menu_key' => $d['menu'], 'audience' => $d['audience'], 'title' => $d['title'], 'summary' => $d['summary'],
                    'steps' => $d['steps'], 'tips' => $d['tips'], 'related' => $d['related'], 'keywords' => $d['keywords'],
                    'required_permissions' => $d['required_permissions'], 'position' => $pos,
                ];
                if (! $entry) {
                    $entry = GuideEntry::create(['key' => $key, 'source' => 'auto', 'generated_at' => now()] + $values);
                    $stats['created'][] = $key;
                } else {
                    $entry->fill($values);
                    if (! $entry->isDirty()) {
                        $stats['unchanged'][] = $key;

                        continue;
                    }
                    $entry->fill(['generated_at' => now(), 'is_stale' => false, 'stale_reason' => null])->save();
                    $stats['updated'][] = $key;
                }
                $this->guides->recordVersion($entry, 'generate', $note, $by);
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
