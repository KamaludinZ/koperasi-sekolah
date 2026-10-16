<?php

namespace App\Services;

use App\Models\Role;
use App\Support\AccessPreview;
use App\Support\GuideCatalog;
use App\Support\Menu;
use Illuminate\Support\Facades\Route;

/**
 * Pemindai menu & izin sistem — bahan panduan otomatis. Membaca:
 * - daftar menu (Menu::ITEMS: label, deskripsi, rute utama, kelompok);
 * - halaman Livewire per menu dari daftar rute beserta penjaganya (menu:, peran:, can:) lewat AccessPreview::pages;
 * - izin per peran dari roles.permissions (peran pemegang tiap menu).
 * Juga melaporkan temuan konfigurasi yang membuat panduan tidak akurat: izin peran berisi menu yang tidak dikenal,
 * rute utama menu yang tidak ada, menu tanpa peran pemegang, dan halaman tanpa judul (#[Title]).
 */
class GuideScanner
{
    public function __construct(private GuideService $guides) {}

    /**
     * Fakta per menu. Halaman berparameter (mis. /barang/{product}) tidak dijadikan langkah; jumlahnya di skipped_pages.
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
     * Izin tiap peran apa adanya di roles.permissions: menu yang dikenal dan kunci yang tidak dikenal.
     *
     * @return array<string, array{label: string, menus: list<string>, unknown: list<string>}>
     */
    public function roles(): array
    {
        $out = [];
        foreach (Role::query()->get(['name', 'label', 'permissions'])->sortBy(fn ($r) => array_search($r->name, Role::PRIORITY, true) === false ? 99 : array_search($r->name, Role::PRIORITY, true)) as $role) {
            $perms = array_values(array_filter((array) $role->permissions, 'is_string'));
            $out[$role->name] = [
                'label' => $role->label ?: (Role::LABELS[$role->name] ?? $role->name),
                'menus' => array_values(array_filter(array_keys(Menu::ITEMS), fn ($m) => in_array($m, $perms, true))),
                'unknown' => array_values(array_diff($perms, array_keys(Menu::ITEMS))),
            ];
        }

        return $out;
    }

    /**
     * Temuan konfigurasi menu & izin.
     *
     * @param  ?array  $facts  hasil scan() bila sudah ada (hemat pemindaian ulang)
     * @return list<array{code: string, menu: ?string, role: ?string, text: string}>
     */
    public function issues(?array $facts = null): array
    {
        $facts ??= $this->scan();
        $issues = [];
        foreach ($this->roles() as $name => $r) {
            foreach ($r['unknown'] as $key) {
                $issues[] = ['code' => 'izin_tidak_dikenal', 'menu' => null, 'role' => $name, 'text' => "Peran {$r['label']} memuat izin “{$key}” yang tidak ada di daftar menu."];
            }
        }
        foreach ($facts as $key => $f) {
            if ($f['route'] && ! Route::has($f['route'])) {
                $issues[] = ['code' => 'rute_tidak_ada', 'menu' => $key, 'role' => null, 'text' => "Rute utama menu {$f['label']} ({$f['route']}) tidak terdaftar."];
            }
            if ($f['roles'] === []) {
                $issues[] = ['code' => 'menu_tanpa_peran', 'menu' => $key, 'role' => null, 'text' => "Menu {$f['label']} belum diberikan ke peran mana pun."];
            }
            foreach ($f['pages'] as $p) {
                if ($p['title'] === $p['name']) {
                    $issues[] = ['code' => 'halaman_tanpa_judul', 'menu' => $key, 'role' => null, 'text' => "Halaman {$p['uri']} di menu {$f['label']} tidak punya judul (#[Title])."];
                }
            }
        }

        return $issues;
    }

    /**
     * Ringkasan pemindaian (untuk perintah & panel).
     *
     * @return array{menus: int, pages: int, role_pages: int, gated_pages: int, skipped_pages: int, roles: int, issues: int}
     */
    public function summary(?array $facts = null): array
    {
        $facts ??= $this->scan();
        $pages = array_merge([], ...array_column($facts, 'pages'));

        return [
            'menus' => count($facts), 'pages' => count($pages),
            'role_pages' => count(array_filter($pages, fn ($p) => $p['roles'] !== [])),
            'gated_pages' => count(array_filter($pages, fn ($p) => $p['ability'] !== null)),
            'skipped_pages' => array_sum(array_column($facts, 'skipped_pages')),
            'roles' => count($this->roles()), 'issues' => count($this->issues($facts)),
        ];
    }
}
