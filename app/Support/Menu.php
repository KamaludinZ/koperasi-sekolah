<?php

namespace App\Support;

use App\Models\Role;

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
        'barang' => [
            'label' => 'Barang & Stok', 'route' => 'barang', 'group' => 'data',
            'description' => 'Daftar barang, harga & stok',
            'icon' => 'M20 7l-8-4-8 4m16 0v10l-8 4m8-14l-8 4m0 10l-8-4V7m8 14V11',
        ],
        'anggota' => [
            'label' => 'Anggota', 'route' => 'anggota', 'group' => 'data',
            'description' => 'Anggota & tabungan',
            'icon' => 'M17 20h5v-2a4 4 0 00-5-3.9M9 20H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zM12 7a3 3 0 11-6 0 3 3 0 016 0zm8 2a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z',
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
        'pengaturan' => [
            'label' => 'Pengaturan', 'route' => 'pengaturan', 'group' => 'pengurus',
            'description' => 'Identitas koperasi, struk & pajak',
            'icon' => 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-2.7 1.1V21a2 2 0 11-4 0v-.1a1.6 1.6 0 00-2.7-1.1l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00-1.1-2.7H3a2 2 0 110-4h.1a1.6 1.6 0 001.1-2.7l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 002.7-1.1V3a2 2 0 114 0v.1a1.6 1.6 0 002.7 1.1l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 001.1 2.7h.1a2 2 0 110 4h-.1a1.6 1.6 0 00-1.5 1z',
        ],
    ];

    /**
     * Admin: semua. Kasir: layanan harian + lihat barang & anggota (setor/tarik).
     * Pengurus: memantau data & laporan, tanpa layar kasir.
     */
    public const DEFAULT_PERMISSIONS = [
        'admin' => ['kasir', 'transaksi', 'barang', 'anggota', 'laporan', 'audit', 'pengguna', 'pengaturan'],
        'kasir' => ['kasir', 'transaksi', 'barang', 'anggota'],
        'pengurus' => ['laporan', 'transaksi', 'barang', 'anggota', 'audit'], // urutan pertama = halaman awal setelah masuk
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

    /** Pesan akses ditolak, mis. "Menu Laporan hanya untuk peran Admin dan Pengurus." */
    public static function deniedMessage(string $menu): string
    {
        $roles = collect(self::DEFAULT_PERMISSIONS)
            ->filter(fn ($menus) => in_array($menu, $menus, true))
            ->keys()
            ->map(fn ($role) => Role::LABELS[$role] ?? $role)
            ->values();
        $list = $roles->count() > 1 ? $roles->slice(0, -1)->join(', ').' dan '.$roles->last() : $roles->join('');

        return 'Menu '.(self::ITEMS[$menu]['label'] ?? $menu).' hanya untuk peran '.$list.'.';
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
