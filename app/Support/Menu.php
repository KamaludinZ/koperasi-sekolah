<?php

namespace App\Support;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

/**
 * Menu utama aplikasi petugas beserta hak akses bawaan tiap peran.
 * Kunci menu = segmen URL pertama (mis. /laporan/... → `laporan`).
 * DEFAULT_PERMISSIONS menjadi isi awal kolom roles.permissions di tahap backend.
 */
class Menu
{
    public const GROUPS = [
        'harian' => 'Layanan harian',
        'data' => 'Data koperasi',
        'pengurus' => 'Pengurus',
    ];

    /** @var array<string, array{label: string, route: ?string, group: string, icon: string, description: string}> */
    public const ITEMS = [
        'kasir' => [
            'label' => 'Kasir', 'route' => 'kasir', 'group' => 'harian',
            'description' => 'Catat penjualan & cetak struk',
            'icon' => 'M3 3h18v4H3zM5 7v13h14V7M9 11h6',
        ],
        'transaksi' => [
            'label' => 'Transaksi', 'route' => 'transaksi', 'group' => 'harian',
            'description' => 'Riwayat penjualan',
            'icon' => 'M9 5h10M9 12h10M9 19h10M5 5h.01M5 12h.01M5 19h.01',
        ],
        'kas' => [
            'label' => 'Kas Harian', 'route' => 'kas', 'group' => 'harian',
            'description' => 'Shift kasir, uang laci & selisih kas',
            'icon' => 'M3 7h18v12H3zM3 7l3-3h12l3 3M16 13h2M7 11v4',
        ],
        'barang' => [
            'label' => 'Barang & Stok', 'route' => 'barang', 'group' => 'data',
            'description' => 'Daftar barang, harga & stok',
            'icon' => 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10l-8-4V7m8 14V11',
        ],
        'pengadaan' => [
            'label' => 'Pengadaan', 'route' => 'pengadaan', 'group' => 'data',
            'description' => 'Supplier, pesanan pembelian, penerimaan & hutang',
            'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
        ],
        'promo' => [
            'label' => 'Diskon & Promo', 'route' => 'promo', 'group' => 'data',
            'description' => 'Promo otomatis, kupon & diskon manual',
            'icon' => 'M9 15l6-6M9.5 9.5h.01M14.5 14.5h.01M7 3h10l4 4v10l-4 4H7l-4-4V7z',
        ],
        'anggota' => [
            'label' => 'Anggota', 'route' => 'anggota', 'group' => 'data',
            'description' => 'Anggota & tabungan',
            'icon' => 'M17 20h5v-2a4 4 0 00-5-3.9M9 20H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zM12 7a3 3 0 11-6 0 3 3 0 016 0zm8 2a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z',
        ],
        'simpanan' => [
            'label' => 'Simpanan', 'route' => 'simpanan', 'group' => 'data',
            'description' => 'Simpanan pokok, wajib & sukarela anggota',
            'icon' => 'M12 3v18M17 7H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6',
        ],
        'pinjaman' => [
            'label' => 'Pinjaman', 'route' => 'pinjaman', 'group' => 'data',
            'description' => 'Pengajuan, pencairan & angsuran pinjaman anggota',
            'icon' => 'M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2zM7 14h3',
        ],
        'laporan' => [
            'label' => 'Laporan', 'route' => 'laporan', 'group' => 'pengurus',
            'description' => 'Rekap penjualan & unduhan',
            'icon' => 'M4 20V10m6 10V4m6 16v-7m4 7H2',
        ],
        'audit' => [
            'label' => 'Audit & Riwayat', 'route' => 'audit', 'group' => 'pengurus',
            'description' => 'Jejak login & aktivitas semua petugas',
            'icon' => 'M12 8v4l3 2M3.05 11a9 9 0 11.5 4M3 4v5h5',
        ],
        'pengguna' => [
            'label' => 'Pengguna', 'route' => 'pengguna', 'group' => 'pengurus',
            'description' => 'Akun petugas & peran',
            'icon' => 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM19 8v6M22 11h-6',
        ],
        'sistem' => [
            'label' => 'Sistem', 'route' => 'sistem', 'group' => 'pengurus',
            'description' => 'Kesehatan sistem, cadangan data & antrean',
            'icon' => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01',
        ],
        'pengaturan' => [
            'label' => 'Pengaturan', 'route' => 'pengaturan', 'group' => 'pengurus',
            'description' => 'Identitas koperasi, struk & pajak',
            'icon' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-2.7 1.1V21a2 2 0 11-4 0v-.1a1.6 1.6 0 00-2.7-1.1l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00-1.1-2.7H3a2 2 0 110-4h.1a1.6 1.6 0 001.1-2.7l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 002.7-1.1V3a2 2 0 114 0v.1a1.6 1.6 0 002.7 1.1l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 001.1 2.7h.1a2 2 0 110 4h-.1a1.6 1.6 0 00-1.5 1z',
        ],
    ];

    /**
     * Admin: semua. Kasir: layanan harian (termasuk shift & kas laci) + lihat barang & anggota (setor/tarik).
     * Pengurus: memantau data, laporan & rekap kas, pengadaan barang, tanpa layar kasir.
     */
    public const DEFAULT_PERMISSIONS = [
        'admin' => ['kasir', 'transaksi', 'kas', 'barang', 'pengadaan', 'promo', 'anggota', 'simpanan', 'pinjaman', 'laporan', 'audit', 'pengguna', 'sistem', 'pengaturan'],
        'kasir' => ['kasir', 'transaksi', 'kas', 'barang', 'promo', 'anggota'],
        'pengurus' => ['laporan', 'transaksi', 'kas', 'barang', 'pengadaan', 'promo', 'anggota', 'simpanan', 'pinjaman', 'audit'], // urutan pertama = halaman awal setelah masuk
    ];

    public static function allows(?string $role, string $menu): bool
    {
        return in_array($menu, self::DEFAULT_PERMISSIONS[$role] ?? [], true);
    }

    /**
     * Hak menu petugas yang sedang masuk: dari roles.permissions (akun sungguhan),
     * atau hak bawaan peran untuk akun tiruan.
     *
     * @param  array{role?: ?string, permissions?: list<string>, is_active?: bool}  $user
     */
    public static function userCan(array $user, string $menu): bool
    {
        if (($user['is_active'] ?? true) === false) {
            return false;
        }

        return in_array($menu, $user['permissions'] ?? self::DEFAULT_PERMISSIONS[$user['role'] ?? ''] ?? [], true);
    }

    /**
     * Petugas boleh membuka rute bernama ini: lolos penjaga menu:… dan peran:… pada rutenya (gabungan semua peran).
     * Dipakai agar tautan lintas menu tidak ditampilkan bila ujungnya 403. Rute tanpa penjaga menu dianggap terbuka.
     *
     * @param  ?array{role?: ?string, roles?: list<string>, permissions?: list<string>, is_active?: bool}  $user  bawaan: petugas yang masuk
     */
    public static function canReach(string $routeName, ?array $user = null): bool
    {
        $user ??= Petugas::current();
        if (! $user) {
            return false;
        }
        [$menu, $roles] = self::routeGuards($routeName);
        if ($menu !== null && ! self::userCan($user, $menu)) {
            return false;
        }

        return $roles === [] || array_intersect($user['roles'] ?? [$user['role'] ?? null], $roles) !== [];
    }

    /**
     * Peran mana dari petugas ini yang memberi tiap menu yang ia punya: [menu => [peran, …]] (urut prioritas).
     * Dipakai untuk menandai menu/halaman yang hanya terbuka lewat sebagian perannya.
     *
     * @param  array{roles?: list<string>, role?: ?string, permissions?: list<string>}  $user
     * @return array<string, list<string>>
     */
    public static function sourcesFor(array $user): array
    {
        $names = $user['roles'] ?? array_filter([$user['role'] ?? null]);
        $perms = Role::whereIn('name', $names)->pluck('permissions', 'name')
            ->map(fn ($p) => is_array($p) ? $p : (json_decode((string) $p, true) ?: []));
        $sources = [];
        foreach ($user['permissions'] ?? [] as $menu) {
            $sources[$menu] = array_values(array_filter($names, fn ($n) => in_array($menu, $perms[$n] ?? [], true)));
        }

        return $sources;
    }

    /**
     * Cakupan peran halaman (rute) yang sedang dibuka bagi petugas multi-peran: peran yang disyaratkan rute
     * (peran:…) dan peran milik petugas yang membukanya. Null bila semua perannya sama-sama membuka halaman ini
     * (tidak ada yang perlu ditandai) atau petugas hanya punya satu peran.
     *
     * @return ?array{required: list<string>, via: list<string>, menu: ?string}
     */
    public static function pageScope(?string $routeName, ?array $user = null): ?array
    {
        $user ??= Petugas::current();
        $held = $user['roles'] ?? [];
        if (! $routeName || ! $user || count($held) < 2) {
            return null;
        }
        [$menu, $required] = self::routeGuards($routeName);
        $via = $held;
        if ($menu !== null) {
            $via = self::sourcesFor($user)[$menu] ?? [];
        }
        if ($required !== []) {
            $via = array_values(array_intersect($via, $required));
        }
        if ($via === [] || count($via) === count($held)) {
            return null;
        }

        return ['required' => $required, 'via' => $via, 'menu' => $menu];
    }

    /** @var array<string, array{0: ?string, 1: list<string>}> */
    private static array $guards = [];

    /** @return array{0: ?string, 1: list<string>} [menu, peran yang dibutuhkan] dari middleware rute */
    private static function routeGuards(string $routeName): array
    {
        if (isset(self::$guards[$routeName])) {
            return self::$guards[$routeName];
        }
        $route = Route::getRoutes()->getByName($routeName);
        $menu = null;
        $roles = [];
        foreach ($route?->gatherMiddleware() ?? [] as $mw) {
            if (! is_string($mw)) {
                continue;
            }
            [$name, $args] = array_pad(explode(':', $mw, 2), 2, '');
            if (in_array($name, ['menu', EnsureMenuAccess::class], true) && $args !== '') {
                $menu = $args;
            } elseif (in_array($name, ['peran', EnsureRole::class], true)) {
                $roles = explode(',', $args);
            }
        }

        return self::$guards[$routeName] = [$menu, $roles];
    }

    /** Menu yang terlihat untuk petugas (lihat for()). */
    public static function forUser(?array $user): array
    {
        if (! $user) {
            return self::for(null);
        }

        $groups = [];
        foreach (self::ITEMS as $key => $item) {
            if (self::userCan($user, $key)) {
                $groups[$item['group']]['label'] = self::GROUPS[$item['group']];
                $groups[$item['group']]['items'][$key] = $item;
            }
        }

        return $groups;
    }

    /**
     * Menu yang boleh dilihat, dikelompokkan. Tanpa peran (belum masuk, tahap demo) = semua menu.
     *
     * @return array<string, array{label: string, items: array<string, array>}>
     */
    public static function for(?string $role): array
    {
        $groups = [];
        foreach (self::ITEMS as $key => $item) {
            if ($role === null || self::allows($role, $key)) {
                $groups[$item['group']]['label'] = self::GROUPS[$item['group']];
                $groups[$item['group']]['items'][$key] = $item;
            }
        }

        return $groups;
    }

    /**
     * Pesan akses ditolak, mis. "Menu Laporan hanya untuk peran Admin dan Pengurus." Mengikuti hak menu peran di
     * basis data (roles.permissions); jatuh ke hak bawaan bila tabel peran belum tersedia.
     */
    public static function deniedMessage(string $menu): string
    {
        $roles = collect(self::grantingRoles($menu))->map(fn ($role) => Role::LABELS[$role] ?? $role)->values();
        $list = $roles->count() > 1 ? $roles->slice(0, -1)->join(', ').' dan '.$roles->last() : $roles->join('');

        return 'Menu '.(self::ITEMS[$menu]['label'] ?? $menu).($list === '' ? ' belum diberikan ke peran mana pun.' : ' hanya untuk peran '.$list.'.');
    }

    /** Peran (urut prioritas) yang memberi menu ini. @return list<string> */
    public static function grantingRoles(string $menu): array
    {
        try {
            $all = Role::query()->pluck('permissions', 'name')->map(fn ($p) => is_array($p) ? $p : (json_decode((string) $p, true) ?: []))->all();
        } catch (\Throwable) {
            $all = self::DEFAULT_PERMISSIONS;
        }

        return Role::sortNames(array_keys(array_filter($all, fn ($menus) => in_array($menu, $menus, true))));
    }

    /**
     * Rincian penolakan rute bernama bagi petugas: menu & peran yang disyaratkan, peran milik petugas beserta
     * apakah tiap peran memenuhi, dan peran mana yang bila ditambahkan akan membuka halaman ini.
     * Null bila rute tak dijaga menu/peran (403 berasal dari aturan lain, mis. kebijakan data).
     *
     * @return ?array{menu: ?string, menu_label: ?string, required: list<string>, held: array<string, bool>, suggest: list<string>}
     */
    public static function denial(?string $routeName, ?array $user = null): ?array
    {
        $user ??= Petugas::current();
        if (! $routeName || ! $user) {
            return null;
        }
        [$menu, $required] = self::routeGuards($routeName);
        if ($menu === null && $required === []) {
            return null;
        }
        $granting = $menu !== null ? self::grantingRoles($menu) : [];
        $passes = fn (string $role) => ($menu === null || in_array($role, $granting, true))
            && ($required === [] || in_array($role, $required, true));
        $held = [];
        foreach ($user['roles'] ?? array_filter([$user['role'] ?? null]) as $r) {
            $held[$r] = $passes($r);
        }

        return [
            'menu' => $menu,
            'menu_label' => $menu ? (self::ITEMS[$menu]['label'] ?? $menu) : null,
            'required' => $required,
            'held' => $held,
            'suggest' => array_values(array_filter(Role::PRIORITY, fn ($r) => ! isset($held[$r]) && $passes($r))),
        ];
    }

    /** Halaman pertama setelah masuk, sesuai peran. */
    public static function homeRoute(?string $role, ?array $permissions = null): string
    {
        foreach ($permissions ?? self::DEFAULT_PERMISSIONS[$role] ?? [] as $key) {
            if ($route = self::ITEMS[$key]['route']) {
                return $route;
            }
        }

        return 'beranda';
    }
}
