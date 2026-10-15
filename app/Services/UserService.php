<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kelola akun petugas & peran (khusus admin): daftar & ringkasan, tambah/ubah akun,
 * atur ulang kata sandi (sesi akun itu diakhiri), aktif/nonaktifkan (akun nonaktif
 * langsung keluar), serta hak menu tiap peran. Penjaga: tidak bisa menonaktifkan /
 * menurunkan akun sendiri, selalu ada minimal satu admin aktif, dan admin tetap
 * memegang menu Pengguna. Semua perubahan tercatat di audit.
 */
class UserService
{
    /** Pesan tunggal untuk aturan "akun wajib punya minimal satu peran" (form, kotak centang, JSON). */
    public const MSG_MIN_ROLE = 'Pilih minimal satu peran — akun tanpa peran tidak bisa membuka menu apa pun.';

    public function __construct(private SessionService $sessions) {}

    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', function ($attr, $value, $fail) use ($ignoreId) {
                $taken = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
                if ($taken) {
                    $fail('Email ini sudah dipakai akun lain.');
                }
            }],
            // multi-peran: `roles` (daftar, minimal satu); `role` tunggal tetap diterima untuk klien lama
            'roles' => ['required_without:role', 'array', 'min:1', 'max:'.count(Role::LABELS)],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')],
            'role' => ['required_without:roles', 'nullable', Rule::exists('roles', 'name')],
            'is_active' => ['boolean'],
            'password' => [$ignoreId ? 'nullable' : 'required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/\d/', 'confirmed'],
        ];
    }

    public static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'email.email' => 'Format email belum benar.',
            'role.exists' => 'Peran tidak dikenal.',
            'roles.required_without' => self::MSG_MIN_ROLE,
            'roles.min' => self::MSG_MIN_ROLE,
            'roles.array' => self::MSG_MIN_ROLE,
            'roles.*.exists' => 'Peran tidak dikenal.',
            'roles.*.distinct' => 'Peran tidak boleh dobel.',
            'password.min' => 'Minimal 8 karakter.',
            'password.regex' => 'Gunakan gabungan huruf dan angka.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    /** @param  array{search?: string, role?: string}  $filters */
    public function query(array $filters = []): Builder
    {
        $term = mb_strtolower(trim($filters['search'] ?? ''));

        return User::query()->with('role', 'roles')
            ->when(($filters['role'] ?? '') === 'tanpa', fn ($q) => $q->whereNull('role_id')->whereDoesntHave('roles'))
            ->when(! in_array($filters['role'] ?? '', ['', 'tanpa'], true), fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $filters['role'])))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->orderByDesc('is_active')
            ->orderByRaw('LOWER(name)');
    }

    public function stats(): array
    {
        $active = User::active()->with('role', 'roles')->get();

        return [
            'active' => $active->count(),
            'inactive' => User::where('is_active', false)->count(),
            // akun multi-peran dihitung di setiap perannya
            ...collect(Role::LABELS)->map(fn ($l, $name) => $active->filter(fn ($u) => in_array($name, $u->roleNames(), true))->count())->all(),
            'multi' => $active->filter(fn ($u) => count($u->roleNames()) > 1)->count(),
            // akun (aktif maupun tidak) yang tidak memegang peran apa pun: tidak bisa membuka menu apa pun
            'no_role' => User::query()->whereNull('role_id')->whereDoesntHave('roles')->count(),
        ];
    }

    /**
     * Daftar peran dari data tervalidasi (`roles` atau `role` tunggal), urut prioritas; peran pertama = peran utama.
     *
     * @return list<string>
     */
    public static function roleList(array $data): array
    {
        $names = Role::sortNames(array_values(array_filter((array) ($data['roles'] ?? [$data['role'] ?? null]), 'is_string')));
        if ($names === []) {
            throw ValidationException::withMessages(['roles' => self::MSG_MIN_ROLE]);
        }

        return $names;
    }

    /** Pasang peran akun: role_id = peran utama, pivot = seluruh peran. */
    private function assignRoles(User $user, array $names): void
    {
        $ids = Role::whereIn('name', $names)->pluck('id', 'name');
        $user->forceFill(['role_id' => $ids[$names[0]]])->save();
        $user->roles()->sync(collect($names)->mapWithKeys(fn ($n) => [$ids[$n] => ['created_at' => now()]])->all());
        $user->load('role', 'roles');
    }

    /** @param  array<string, mixed>  $data  data tervalidasi */
    public function create(array $data): User
    {
        $names = self::roleList($data);
        $user = DB::transaction(function () use ($data, $names) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                'role_id' => Role::named($names[0])->id,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $this->assignRoles($user, $names);

            return $user;
        });
        $labels = implode(' + ', array_map(fn ($n) => Role::LABELS[$n] ?? $n, $names));

        AuditLog::record('user.created', 'pengguna', "Akun baru {$user->name} sebagai {$labels}", self::subject($user),
            ['email' => $user->email, 'role' => $names[0], 'roles' => $names],
            action: 'dibuat', new: ['name' => $user->name, 'email' => $user->email, 'role' => $names[0], 'roles' => $names, 'is_active' => (bool) $user->is_active]);

        return $user;
    }

    /** @param  array<string, mixed>  $data  data tervalidasi */
    public function update(User $user, array $data, ?User $actor): User
    {
        $user->loadMissing('role', 'roles');
        $names = self::roleList($data); // akun tanpa peran ditolak (MSG_MIN_ROLE)
        $active = (bool) ($data['is_active'] ?? $user->is_active);
        $this->guardChange($user, $names, $active, $actor);

        $before = ['name' => $user->name, 'email' => $user->email, 'roles' => implode(', ', $user->roleNames()), 'is_active' => (bool) $user->is_active];
        $beforeRoles = $user->roleNames();

        DB::transaction(function () use ($user, $data, $names, $active) {
            $user->forceFill([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'is_active' => $active,
            ]);
            if (filled($data['password'] ?? null)) {
                $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()]);
            }
            $user->save();
            $this->assignRoles($user, $names);
        });

        $after = ['name' => $user->name, 'email' => $user->email, 'roles' => implode(', ', $user->roleNames()), 'is_active' => (bool) $user->is_active];
        if ($changes = AuditLog::diff($before, $after, array_keys($after))) {
            $roleChange = isset($changes['roles']) ? ['added' => array_values(array_diff($names, $beforeRoles)), 'removed' => array_values(array_diff($beforeRoles, $names))] : null;
            AuditLog::record($roleChange ? 'user.role_changed' : 'user.updated', 'pengguna',
                "Akun {$user->name} diubah: ".implode(', ', array_keys($changes)), self::subject($user), ['changes' => $changes] + ($roleChange ?? []),
                action: 'diubah', old: array_map(fn ($c) => $c['from'], $changes), new: array_map(fn ($c) => $c['to'], $changes));
        }
        if (filled($data['password'] ?? null)) {
            $this->sessions->revokeAll($user);
            AuditLog::record('user.password_reset', 'pengguna', "Kata sandi akun {$user->name} diatur ulang (semua sesinya diakhiri)", self::subject($user),
                action: 'diubah', new: ['password' => '(diatur ulang)']);
        } elseif (! $user->is_active && $before['is_active']) {
            $this->sessions->revokeAll($user);
        }

        return $user;
    }

    /**
     * Ubah HANYA peran akun (form kotak centang "Ubah peran"): minimal satu peran, aturan admin sama dengan update(),
     * jejak `user.role_changed` dengan peran ditambah/dicabut. Tidak ada perubahan → tidak dicatat.
     *
     * @param  list<string>  $roles
     * @return array{user: User, added: list<string>, removed: list<string>}
     */
    public function updateRoles(User $user, array $roles, ?User $actor): array
    {
        $user->loadMissing('role', 'roles');
        $known = array_keys(Role::LABELS);
        if ($unknown = array_diff($roles, $known)) {
            throw ValidationException::withMessages(['roles' => 'Peran tidak dikenal: '.implode(', ', $unknown).'.']);
        }
        $names = self::roleList(['roles' => $roles]);
        $this->guardChange($user, $names, (bool) $user->is_active, $actor);
        $before = $user->roleNames();
        $added = array_values(array_diff($names, $before));
        $removed = array_values(array_diff($before, $names));
        if ($added === [] && $removed === []) {
            return ['user' => $user, 'added' => [], 'removed' => []];
        }

        DB::transaction(function () use ($user, $names, $before, $added, $removed) {
            $this->assignRoles($user, $names);
            $label = fn (array $n) => implode(', ', array_map(fn ($x) => Role::LABELS[$x] ?? $x, $n));
            AuditLog::record('user.role_changed', 'pengguna',
                "Peran {$user->name} diubah".($added ? ': + '.$label($added) : '').($removed ? ($added ? ';' : ':').' − '.$label($removed) : ''),
                self::subject($user), ['changes' => ['roles' => ['from' => implode(', ', $before), 'to' => implode(', ', $names)]], 'added' => $added, 'removed' => $removed],
                action: 'diubah', old: ['roles' => implode(', ', $before)], new: ['roles' => implode(', ', $names)]);
        });

        return ['user' => $user, 'added' => $added, 'removed' => $removed];
    }

    /**
     * Periksa (dan bila $fix, perbaiki) konsistensi peran akun:
     * - peran utama (role_id) belum tercatat di pivot → ditambahkan ke pivot;
     * - peran utama bukan prioritas tertinggi dari peran yang dipegang → role_id disesuaikan;
     * - akun tanpa peran sama sekali → hanya dilaporkan (admin harus memilih perannya).
     *
     * @return array{missing_pivot: list<int>, wrong_primary: list<int>, no_role: list<int>, fixed: int}
     */
    public function roleConsistency(bool $fix = false): array
    {
        $r = ['missing_pivot' => [], 'wrong_primary' => [], 'no_role' => [], 'fixed' => 0];
        $roleIds = Role::pluck('id', 'name');
        User::with('role', 'roles')->orderBy('id')->each(function (User $u) use (&$r, $fix, $roleIds) {
            $held = $u->roles->pluck('name')->all();
            if ($u->role_id && ! $u->roles->contains('id', $u->role_id)) {
                $r['missing_pivot'][] = $u->id;
                if ($fix) {
                    DB::table('user_roles')->insertOrIgnore(['user_id' => $u->id, 'role_id' => $u->role_id, 'created_at' => now()]);
                    $held[] = $u->role->name;
                    $r['fixed']++;
                }
            }
            if ($held === [] && ! $u->role_id) {
                $r['no_role'][] = $u->id;

                return;
            }
            $best = Role::sortNames($held)[0] ?? null;
            if ($best && $u->role?->name !== $best) {
                $r['wrong_primary'][] = $u->id;
                if ($fix) {
                    $u->forceFill(['role_id' => $roleIds[$best]])->saveQuietly();
                    $r['fixed']++;
                }
            }
        });
        if ($fix && $r['fixed'] > 0) {
            AuditLog::record('user.roles_repaired', 'pengguna', "Konsistensi peran akun diperbaiki ({$r['fixed']} perbaikan)", null, $r);
        }

        return $r;
    }

    /** Tambah satu peran ke akun (peran lain tetap). */
    public function addRole(User $user, string $role, ?User $actor): array
    {
        $user->loadMissing('role', 'roles');

        return $this->updateRoles($user, [...$user->roleNames(), $role], $actor);
    }

    /** Cabut satu peran dari akun; peran terakhir tidak bisa dicabut (akun wajib punya minimal satu peran). */
    public function removeRole(User $user, string $role, ?User $actor): array
    {
        $user->loadMissing('role', 'roles');
        $rest = array_values(array_diff($user->roleNames(), [$role]));
        if (! in_array($role, $user->roleNames(), true)) {
            throw ValidationException::withMessages(['roles' => "Akun {$user->name} tidak memegang peran ".(Role::LABELS[$role] ?? $role).'.']);
        }
        if ($rest === []) {
            throw ValidationException::withMessages(['roles' => "Peran terakhir {$user->name} tidak bisa dicabut — akun wajib punya minimal satu peran."]);
        }

        return $this->updateRoles($user, $rest, $actor);
    }

    public function toggleActive(User $user, ?User $actor): User
    {
        $user->loadMissing('role', 'roles');
        if (! $user->is_active && $user->roleNames() === []) {
            throw ValidationException::withMessages(['roles' => "Tetapkan minimal satu peran untuk {$user->name} sebelum mengaktifkannya."]);
        }
        $this->guardChange($user, $user->roleNames(), ! $user->is_active, $actor);

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        if (! $user->is_active) {
            $this->sessions->revokeAll($user);
        }

        AuditLog::record($user->is_active ? 'user.activated' : 'user.deactivated', 'pengguna',
            'Akun '.$user->name.($user->is_active ? ' diaktifkan' : ' dinonaktifkan dan dikeluarkan dari semua perangkat'), self::subject($user),
            action: 'diubah', old: ['is_active' => ! $user->is_active], new: ['is_active' => (bool) $user->is_active]);

        return $user;
    }

    /**
     * Ubah daftar menu yang boleh dibuka sebuah peran.
     *
     * @param  list<string>  $menus
     */
    public function updatePermissions(Role $role, array $menus): Role
    {
        $menus = array_values(array_intersect(array_keys(Menu::ITEMS), $menus)); // urutan & kunci valid saja
        if ($forbidden = array_intersect($menus, Role::FORBIDDEN[$role->name] ?? [])) {
            $names = implode(', ', array_map(fn ($k) => Menu::ITEMS[$k]['label'] ?? $k, $forbidden));
            throw ValidationException::withMessages(['permissions' => "Menu {$names} tidak boleh diberikan ke peran {$role->label}."]);
        }
        if ($role->name === Role::ADMIN && array_diff(Role::ADMIN_REQUIRED, $menus)) {
            throw ValidationException::withMessages(['permissions' => 'Peran Admin wajib tetap bisa membuka menu Pengguna dan Pengaturan.']);
        }

        $before = $role->permissions ?? [];
        $role->update(['permissions' => $menus]);

        $added = array_values(array_diff($menus, $before));
        $removed = array_values(array_diff($before, $menus));
        if ($added || $removed) {
            $label = fn (array $keys) => implode(', ', array_map(fn ($k) => Menu::ITEMS[$k]['label'] ?? $k, $keys));
            AuditLog::record('role.permissions_updated', 'pengguna',
                "Hak akses peran {$role->label} diubah".($added ? ' (+'.$label($added).')' : '').($removed ? ' (−'.$label($removed).')' : ''),
                ['type' => 'role', 'id' => $role->id, 'label' => $role->label], ['added' => $added, 'removed' => $removed],
                action: 'diubah', old: ['menus' => $label($before)], new: ['menus' => $label($menus)]);
        }

        return $role;
    }

    public static function toArray(User $user): array
    {
        return [
            ...AuthService::toArray($user),
            'created_at' => $user->created_at?->toDateTimeString(),
            'password_changed_at' => $user->password_changed_at?->toDateTimeString(),
        ];
    }

    /** @throws ValidationException */
    /** @param  list<string>  $newRoles */
    private function guardChange(User $user, array $newRoles, bool $newActive, ?User $actor): void
    {
        $errors = [];
        $wasAdmin = in_array(Role::ADMIN, $user->roleNames(), true);
        $staysAdmin = in_array(Role::ADMIN, $newRoles, true);
        if ($actor && $actor->is($user)) {
            if (! $newActive) {
                $errors['is_active'] = 'Anda tidak bisa menonaktifkan akun sendiri.';
            }
            if ($wasAdmin && ! $staysAdmin) {
                $errors['roles'] = 'Anda tidak bisa mencabut peran admin dari akun sendiri.';
            }
        }
        $losesAdmin = $wasAdmin && $user->is_active && (! $staysAdmin || ! $newActive);
        if (! isset($errors['roles']) && $losesAdmin
            && User::active()->whereKeyNot($user->id)->whereHas('roles', fn ($r) => $r->where('name', Role::ADMIN))->doesntExist()) {
            $errors['roles'] = 'Harus ada minimal satu admin aktif.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function subject(User $user): array
    {
        return ['type' => 'user', 'id' => $user->id, 'label' => "{$user->name} · {$user->email}"];
    }
}
