<?php

namespace App\Support;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionMerger;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Simulasi hak akses gabungan untuk kombinasi peran tiruan (tanpa akun & tanpa menyimpan apa pun):
 * menu gabungan beserta asal perannya, halaman awal setelah masuk, hak aksi (gate) dan halaman
 * Livewire yang terbuka. Dihitung dengan User tiruan di memori sehingga memakai aturan yang sama
 * persis dengan akun sungguhan (User::permissions, hasRole, canOpen, Gate).
 */
class AccessPreview
{
    /** Hak aksi (Gate) di luar menu, dengan penjelasan singkat. */
    public const ABILITIES = [
        'kelola-shift' => 'Buka/tutup shift kasir & catat uang laci',
        'setujui-retur' => 'Setujui retur & pembatalan transaksi',
        'kelola-katalog' => 'Kelola kategori & impor barang',
        'kelola-pengadaan' => 'Kelola supplier, pesanan & hutang pengadaan',
        'kelola-anggota' => 'Hapus anggota & atur batas bon khusus',
        'kelola-simpanan' => 'Kelola jenis & mutasi simpanan',
        'kelola-pinjaman' => 'Kelola pengajuan, pencairan & angsuran pinjaman',
        'lihat-rekap-pinjaman' => 'Lihat rekap & ekspor pinjaman',
        'pulihkan-data' => 'Pulihkan data yang terhapus',
        'ubah-pengaturan' => 'Ubah profil koperasi, struk & pajak',
    ];

    /** @var Collection<string, Role> */
    private Collection $roles;

    public function __construct(?Collection $roles = null)
    {
        $this->roles = ($roles ?? Role::orderBy('id')->get())->keyBy('name');
    }

    /** Semua peran yang ada, urut prioritas. @return Collection<string, Role> */
    public function roles(): Collection
    {
        return collect(Role::sortNames($this->roles->keys()->all()))->mapWithKeys(fn ($n) => [$n => $this->roles[$n]]);
    }

    /** Nama peran yang valid saja, urut prioritas, tanpa duplikat. @return list<string> */
    public function normalize(array $names): array
    {
        return Role::sortNames(array_values(array_intersect(array_unique($names), $this->roles->keys()->all())));
    }

    /** Akun tiruan di memori dengan kombinasi peran ini (peran utama = prioritas tertinggi). */
    public function fakeUser(array $names): User
    {
        $names = $this->normalize($names);
        $user = (new User)->forceFill(['name' => 'Simulasi', 'is_active' => true]);
        $user->setRelation('roles', collect($names)->map(fn ($n) => $this->roles[$n])->values());
        $user->setRelation('role', $names ? $this->roles[$names[0]] : null);

        return $user;
    }

    /**
     * Hasil simulasi lengkap untuk satu kombinasi peran.
     *
     * @return array{roles: list<string>, primary: ?string, permissions: list<string>, sources: array<string, list<string>>,
     *               home: string, menu: array, abilities: array<string, array{label: string, allowed: bool, combined: bool}>,
     *               pages: list<array{name: string, title: string, uri: string, menu: string, roles: list<string>, ability: ?string, allowed: bool, reason: ?string}>}
     */
    public function simulate(array $names): array
    {
        $names = $this->normalize($names);
        $user = $this->fakeUser($names);
        $merged = app(PermissionMerger::class)->forUser($user);
        $permissions = $names ? $merged['permissions'] : [];
        $sources = $names ? $merged['sources'] : [];

        $singles = collect($names)->map(fn ($n) => $this->fakeUser([$n]));
        $abilities = [];
        foreach (self::ABILITIES as $ability => $label) {
            $allowed = $names !== [] && Gate::forUser($user)->allows($ability);
            $abilities[$ability] = [
                'label' => $label,
                'allowed' => $allowed,
                // terbuka hanya karena gabungan: tak satu pun peran tunggalnya memberi hak ini
                'combined' => $allowed && $singles->every(fn ($u) => Gate::forUser($u)->denies($ability)),
            ];
        }

        $pages = collect($this->pages())->map(function ($p) use ($user, $names) {
            [$allowed, $reason] = $this->pageAccess($user, $names, $p);

            return [...$p, 'allowed' => $allowed, 'reason' => $reason];
        })->all();

        return [
            'roles' => $names,
            'primary' => $names[0] ?? null,
            'permissions' => $permissions,
            'sources' => $sources,
            'home' => $names ? Menu::homeRoute($names[0], $permissions) : 'beranda',
            'menu' => $names ? Menu::forUser(['role' => $names[0], 'permissions' => $permissions]) : [],
            'abilities' => $abilities,
            'pages' => $pages,
        ];
    }

    /**
     * Halaman Livewire petugas yang dijaga menu (GET bernama), dibaca dari daftar rute sehingga
     * selalu mengikuti middleware menu:/peran:/can: yang sebenarnya.
     *
     * @return list<array{name: string, title: string, uri: string, menu: string, roles: list<string>, ability: ?string}>
     */
    public function pages(): array
    {
        $pages = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $class = $route->getControllerClass();
            if (! in_array('GET', $route->methods(), true) || ! $route->getName() || ! $class || ! is_subclass_of($class, Component::class)) {
                continue;
            }
            $menu = null;
            $roles = [];
            $ability = null;
            foreach ($route->gatherMiddleware() as $mw) {
                if (! is_string($mw)) {
                    continue;
                }
                [$name, $args] = array_pad(explode(':', $mw, 2), 2, '');
                if (in_array($name, ['menu', EnsureMenuAccess::class], true) && $args !== '') {
                    $menu = $args;
                } elseif (in_array($name, ['peran', EnsureRole::class], true)) {
                    $roles = explode(',', $args); // mentah (boleh diawali `semua`), dinilai EnsureRole::allows
                } elseif (in_array($name, ['can', Authorize::class], true) && ! str_contains($args, ',')) {
                    $ability = $args; // gate tanpa model; can:x,model dinilai per data
                }
            }
            if ($menu === null || ! isset(Menu::ITEMS[$menu])) {
                continue;
            }
            $title = (new \ReflectionClass($class))->getAttributes(Title::class)[0] ?? null;
            $pages[] = [
                'name' => $route->getName(),
                'title' => $title ? (string) $title->getArguments()[0] : $route->getName(),
                'uri' => '/'.ltrim($route->uri(), '/'),
                'menu' => $menu,
                'roles' => $roles,
                'ability' => $ability,
            ];
        }

        $order = array_flip(array_keys(Menu::ITEMS));
        usort($pages, fn ($a, $b) => [$order[$a['menu']], $a['uri']] <=> [$order[$b['menu']], $b['uri']]);

        return $pages;
    }

    /** @return array{0: bool, 1: ?string} boleh dibuka + alasan bila ditolak */
    private function pageAccess(User $user, array $names, array $page): array
    {
        if ($names === []) {
            return [false, 'Tanpa peran'];
        }
        if (! $user->canOpen($page['menu'])) {
            return [false, 'Tidak punya menu '.Menu::ITEMS[$page['menu']]['label']];
        }
        if ($page['roles'] && ! EnsureRole::allows($names, $page['roles'])) {
            ['roles' => $req, 'all' => $all] = EnsureRole::parse($page['roles']);

            return [false, 'Khusus '.collect($req)->map(fn ($r) => Role::LABELS[$r] ?? $r)->join($all ? ' + ' : ' / ')];
        }
        if ($page['ability'] && Gate::forUser($user)->denies($page['ability'])) {
            return [false, 'Butuh hak: '.(self::ABILITIES[$page['ability']] ?? $page['ability'])];
        }

        return [true, null];
    }

    /**
     * Kombinasi peran yang dipegang akun petugas sungguhan, dengan jumlah akunnya (terbanyak dulu).
     *
     * @return list<array{roles: list<string>, count: int}>
     */
    public static function usedCombinations(): array
    {
        return User::query()->with(['roles', 'role'])->get()
            ->map(fn (User $u) => $u->roleNames())
            ->filter()
            ->groupBy(fn ($names) => implode('+', $names))
            ->map(fn ($group) => ['roles' => $group->first(), 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
