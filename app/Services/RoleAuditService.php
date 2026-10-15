<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Menu;

/**
 * Pencatatan perubahan peran ke activity_logs (ber-HMAC) dengan diff tambah/cabut yang seragam untuk SEMUA jalur:
 * simpan peran (PUT /pengguna/{user}/peran), tambah/cabut satu peran, ubah akun lewat formulir, buat akun, perubahan
 * pivot di luar alur resmi (UserRole), dan perubahan hak menu peran. Isi properties (selain kompatibel dengan format
 * lama: changes.roles, added, removed):
 * - before/after (nama peran urut prioritas), primary_from/primary_to (peran utama),
 * - menus_gained/menus_lost (dampak pada menu gabungan akun, kunci Menu::ITEMS), source (jalur perubahan).
 * Alasan (opsional) disimpan di kolom reason. Pelaku = petugas yang masuk (admin pelaku selama impersonasi).
 */
class RoleAuditService
{
    public const SOURCES = [
        'peran' => 'Simpan peran',
        'tambah' => 'Tambah satu peran',
        'cabut' => 'Cabut satu peran',
        'formulir' => 'Formulir akun',
        'akun_baru' => 'Akun baru',
        'otomatis' => 'Di luar halaman Pengguna',
    ];

    public function __construct(private PermissionMerger $merger) {}

    /**
     * Diff dua daftar peran (nama). Urutan hasil mengikuti prioritas peran.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{added: list<string>, removed: list<string>, kept: list<string>}
     */
    public function diff(array $before, array $after): array
    {
        $before = Role::sortNames(array_values(array_unique($before)));
        $after = Role::sortNames(array_values(array_unique($after)));

        return [
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
            'kept' => array_values(array_intersect($after, $before)),
        ];
    }

    /**
     * Catat perubahan peran sebuah akun. Tidak ada beda (dan tak ada perubahan lain) → tidak dicatat (null).
     *
     * @param  list<string>  $before  peran sebelum (urut prioritas, peran utama di depan bila dari roleNames)
     * @param  list<string>  $after  peran sesudah
     * @param  array{source?: string, reason?: ?string, primary_from?: ?string, primary_to?: ?string, other_changes?: array<string, array{from: mixed, to: mixed}>, description?: string, event?: string}  $ctx
     */
    public function recordUserRoles(User $user, array $before, array $after, array $ctx = []): ?ActivityLog
    {
        $d = $this->diff($before, $after);
        $other = $ctx['other_changes'] ?? [];
        if ($d['added'] === [] && $d['removed'] === [] && $other === []) {
            return null;
        }
        $roleChanged = $d['added'] !== [] || $d['removed'] !== [];
        $menusBefore = $this->merger->mergeNames($before, $ctx['primary_from'] ?? null)['permissions'];
        $menusAfter = $this->merger->mergeNames($after, $ctx['primary_to'] ?? null)['permissions'];
        $label = fn (array $n) => implode(', ', array_map(fn ($x) => Role::LABELS[$x] ?? $x, $n));

        $changes = $other;
        if ($roleChanged) {
            $changes['roles'] = ['from' => implode(', ', Role::sortNames($before)), 'to' => implode(', ', Role::sortNames($after))];
        }
        $description = $ctx['description'] ?? ("Peran {$user->name} diubah"
            .($d['added'] ? ': + '.$label($d['added']) : '').($d['removed'] ? ($d['added'] ? ';' : ':').' − '.$label($d['removed']) : ''));

        $properties = ['changes' => $changes];
        if ($roleChanged) {
            $properties += [
                'added' => $d['added'], 'removed' => $d['removed'],
                'before' => Role::sortNames($before), 'after' => Role::sortNames($after),
                'primary_from' => $ctx['primary_from'] ?? ($before[0] ?? null), 'primary_to' => $ctx['primary_to'] ?? ($after[0] ?? null),
                'menus_gained' => array_values(array_diff($menusAfter, $menusBefore)), 'menus_lost' => array_values(array_diff($menusBefore, $menusAfter)),
                'source' => $ctx['source'] ?? 'peran',
            ];
        }

        return AuditLog::record($ctx['event'] ?? ($roleChanged ? 'user.role_changed' : 'user.updated'), 'pengguna', $description,
            UserService::subjectFor($user), $properties,
            action: 'diubah', old: array_map(fn ($c) => $c['from'], $changes), new: array_map(fn ($c) => $c['to'], $changes),
            reason: filled($ctx['reason'] ?? null) ? trim((string) $ctx['reason']) : null);
    }

    /** Catat peran awal akun baru (bagian dari user.created). @return array<string, mixed> properti tambahan */
    public function createdProperties(array $roles): array
    {
        $roles = Role::sortNames($roles);

        return ['roles' => $roles, 'before' => [], 'after' => $roles, 'added' => $roles, 'removed' => [],
            'primary_to' => $roles[0] ?? null, 'menus_gained' => $this->merger->mergeNames($roles)['permissions'], 'source' => 'akun_baru'];
    }

    /**
     * Satu baris pivot ditambah/dicabut di LUAR alur resmi (jaring pengaman UserRole): dicatat dengan diff yang sama.
     */
    public function recordPivotChange(string $kind, User $user, Role $role): ?ActivityLog
    {
        $now = $user->fresh(['role', 'roles'])?->roleNames() ?? [];
        $before = $kind === 'added' ? array_values(array_diff($now, [$role->name])) : Role::sortNames([...$now, $role->name]);

        return $this->recordUserRoles($user, $before, $now, [
            'source' => 'otomatis', 'event' => "user.role_{$kind}",
            'description' => 'Peran '.$role->label.($kind === 'added' ? ' ditambahkan ke ' : ' dicabut dari ').$user->name.' (di luar halaman Pengguna)',
        ]) ?? null;
    }

    /**
     * Catat perubahan hak menu sebuah peran: menu ditambah/dicabut, jumlah & daftar (≤50) akun pemegang yang terdampak.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function recordRolePermissions(Role $role, array $before, array $after, ?string $reason = null): ?ActivityLog
    {
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));
        if ($added === [] && $removed === []) {
            return null;
        }
        $label = fn (array $keys) => implode(', ', array_map(fn ($k) => Menu::ITEMS[$k]['label'] ?? $k, $keys));
        $holders = User::holdingRole($role->name)->orderBy('id')->pluck('id');

        return AuditLog::record('role.permissions_updated', 'pengguna',
            "Hak akses peran {$role->label} diubah".($added ? ' (+'.$label($added).')' : '').($removed ? ' (−'.$label($removed).')' : ''),
            ['type' => 'role', 'id' => $role->id, 'label' => $role->label],
            ['added' => $added, 'removed' => $removed, 'before' => array_values($before), 'after' => array_values($after),
                'holders_count' => $holders->count(), 'holder_ids' => $holders->take(50)->all()],
            action: 'diubah', old: ['menus' => $label($before)], new: ['menus' => $label($after)],
            reason: filled($reason) ? trim((string) $reason) : null);
    }
}
