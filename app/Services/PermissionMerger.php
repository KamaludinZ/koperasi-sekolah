<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\AccessPreview;
use App\Support\Menu;
use Illuminate\Support\Facades\Gate;

/**
 * Layanan penggabungan izin antar peran — satu-satunya tempat aturan "hak akses = gabungan semua peran":
 * - izin menu = gabungan (union) roles.permissions semua peran yang dipegang; tak ada yang saling menimpa/mengurangi;
 * - urutan: izin peran utama dulu (menentukan halaman awal setelah masuk), lalu tambahan dari peran lain menurut
 *   prioritas peran (admin, pengurus, kasir); tanpa duplikat;
 * - kunci menu yang tidak dikenal (tidak ada di Menu::ITEMS, mis. sisa data lama) diabaikan;
 * - asal tiap izin (peran mana yang memberinya) ikut dihitung untuk penanda di antarmuka & audit.
 * Dipakai User::permissions(), Menu::sourcesFor(), simulasi hak akses, dan endpoint izin pengguna.
 */
class PermissionMerger
{
    /**
     * Gabungkan izin dari peran-peran (model Role yang sudah dimuat).
     *
     * @param  iterable<Role>  $roles
     * @return array{roles: list<string>, primary: ?string, permissions: list<string>, sources: array<string, list<string>>}
     */
    public function merge(iterable $roles, ?string $primary = null): array
    {
        $byName = collect($roles)->filter()->keyBy('name');
        $names = Role::sortNames($byName->keys()->all());
        $primary = $primary !== null && $byName->has($primary) ? $primary : ($names[0] ?? null);
        $order = array_values(array_unique(array_filter([$primary, ...$names])));

        $permissions = [];
        $sources = [];
        foreach ($order as $name) {
            foreach ((array) ($byName[$name]->permissions ?? []) as $menu) {
                if (! is_string($menu) || ! isset(Menu::ITEMS[$menu])) {
                    continue;
                }
                if (! isset($sources[$menu])) {
                    $permissions[] = $menu;
                }
                $sources[$menu][] = $name;
            }
        }
        foreach ($sources as $menu => $from) {
            $sources[$menu] = Role::sortNames(array_values(array_unique($from)));
        }

        return ['roles' => $names, 'primary' => $primary, 'permissions' => $permissions, 'sources' => $sources];
    }

    /**
     * Gabungan izin berdasar nama peran (dimuat dari basis data). Nama yang tidak dikenal diabaikan.
     *
     * @param  list<string>  $names
     */
    public function mergeNames(array $names, ?string $primary = null): array
    {
        $names = array_values(array_filter($names, 'is_string'));

        return $this->merge($names === [] ? [] : Role::whereIn('name', $names)->get(), $primary);
    }

    /** Gabungan izin akun (peran pivot + peran utama users.role_id). */
    public function forUser(User $user): array
    {
        $user->loadMissing('role', 'roles');
        $roles = $user->roles->all();
        if ($user->role && ! $user->roles->contains('id', $user->role->id)) {
            $roles[] = $user->role;
        }

        return $this->merge($roles, $user->role?->name);
    }

    /**
     * Ringkasan hak akses efektif akun: gabungan izin + asal peran, menu berlabel, halaman awal, dan hak aksi (Gate)
     * — termasuk yang hanya muncul karena gabungan peran. Akun nonaktif tidak membuka apa pun.
     *
     * @return array{roles: list<string>, primary: ?string, active: bool, permissions: list<string>, sources: array<string, list<string>>,
     *               menus: list<array{key: string, label: string, from: list<string>, partial: bool}>, home: string,
     *               abilities: array<string, array{label: string, allowed: bool, combined: bool}>}
     */
    public function effective(User $user): array
    {
        $merged = $this->forUser($user);
        $active = (bool) $user->is_active;
        $preview = new AccessPreview;
        $singles = collect($merged['roles'])->map(fn ($n) => $preview->fakeUser([$n]));

        $abilities = [];
        foreach (AccessPreview::ABILITIES as $ability => $label) {
            $allowed = $active && $merged['roles'] !== [] && Gate::forUser($user)->allows($ability);
            $abilities[$ability] = [
                'label' => $label,
                'allowed' => $allowed,
                'combined' => $allowed && count($merged['roles']) > 1 && $singles->every(fn ($u) => Gate::forUser($u)->denies($ability)),
            ];
        }

        return [
            ...$merged,
            'active' => $active,
            'permissions' => $active ? $merged['permissions'] : [],
            'menus' => $active ? array_map(fn ($k) => [
                'key' => $k,
                'label' => Menu::ITEMS[$k]['label'],
                'from' => $merged['sources'][$k],
                'partial' => count($merged['roles']) > 1 && count($merged['sources'][$k]) < count($merged['roles']),
            ], $merged['permissions']) : [],
            'home' => $active && $merged['permissions'] ? Menu::homeRoute($merged['primary'], $merged['permissions']) : 'beranda',
            'abilities' => $abilities,
        ];
    }
}
