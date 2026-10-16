<?php

namespace App\Services;

use App\Models\GuideMenuSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Metadata menu & izin untuk panduan otomatis: membaca hasil pemindai (GuideGenerator::scan), menghitung sidik jari
 * per aspek (menu, izin peran, halaman) dan membandingkannya dengan guide_menu_snapshots. capture() menyimpan
 * keadaan terbaru dan mengembalikan perubahan sejak pemindaian sebelumnya (menu baru, dihapus, dan aspek yang
 * berubah per menu) — bahan sinkronisasi saat menu/izin berubah dan penanda panduan usang.
 */
class GuideMetadataService
{
    public function __construct(private GuideGenerator $generator) {}

    /** @return array<string, array{label: string, route: ?string, roles: list<string>, pages: list<array>, hashes: array{menu: string, roles: string, pages: string}}> */
    public function current(): array
    {
        $out = [];
        foreach ($this->generator->scan() as $key => $f) {
            $roles = array_values($f['roles']);
            sort($roles);
            $pages = array_map(fn ($p) => ['name' => $p['name'], 'title' => $p['title'], 'uri' => $p['uri'], 'roles' => array_values($p['roles']), 'ability' => $p['ability']], $f['pages']);
            usort($pages, fn ($a, $b) => strcmp($a['name'], $b['name']));
            $out[$key] = [
                'label' => $f['label'], 'route' => $f['route'], 'roles' => $f['roles'], 'pages' => $pages,
                'hashes' => [
                    'menu' => $this->hash([$f['label'], $f['route'], $f['description']]),
                    'roles' => $this->hash($roles),
                    'pages' => $this->hash($pages),
                ],
            ];
        }

        return $out;
    }

    /**
     * Bandingkan keadaan sekarang dengan snapshot tersimpan tanpa menyimpan.
     *
     * @return array{added: list<string>, removed: list<string>, changed: array<string, list<string>>, first_scan: bool}
     */
    public function diff(?array $current = null): array
    {
        $current ??= $this->current();
        $stored = GuideMenuSnapshot::all()->keyBy('menu_key');
        $changed = [];
        foreach ($current as $key => $c) {
            if ($s = $stored[$key] ?? null) {
                $aspects = array_keys(array_filter(['menu' => $s->menu_hash !== $c['hashes']['menu'], 'roles' => $s->roles_hash !== $c['hashes']['roles'], 'pages' => $s->pages_hash !== $c['hashes']['pages']]));
                if ($aspects) {
                    $changed[$key] = $aspects;
                }
            }
        }

        return [
            'added' => $stored->isEmpty() ? [] : array_values(array_diff(array_keys($current), $stored->keys()->all())),
            'removed' => array_values(array_diff($stored->keys()->all(), array_keys($current))),
            'changed' => $changed,
            'first_scan' => $stored->isEmpty(),
        ];
    }

    /**
     * Simpan keadaan terbaru (upsert per menu, hapus menu yang sudah tiada) dan kembalikan perubahannya.
     *
     * @return array{added: list<string>, removed: list<string>, changed: array<string, list<string>>, first_scan: bool}
     */
    public function capture(): array
    {
        $current = $this->current();

        return DB::transaction(function () use ($current) {
            $diff = $this->diff($current);
            $now = now();
            foreach ($current as $key => $c) {
                $row = GuideMenuSnapshot::firstOrNew(['menu_key' => $key]);
                $changed = ! $row->exists || isset($diff['changed'][$key]);
                $row->fill([
                    'label' => $c['label'], 'route' => $c['route'], 'roles' => $c['roles'], 'pages' => $c['pages'],
                    'menu_hash' => $c['hashes']['menu'], 'roles_hash' => $c['hashes']['roles'], 'pages_hash' => $c['hashes']['pages'],
                    'scanned_at' => $now, 'changed_at' => $changed ? $now : $row->changed_at,
                ])->save();
            }
            if ($diff['removed']) {
                GuideMenuSnapshot::whereIn('menu_key', $diff['removed'])->delete();
            }

            return $diff;
        });
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
