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
            'role' => ['required', Rule::exists('roles', 'name')],
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

        return User::query()->with('role')
            ->when(($filters['role'] ?? '') !== '', fn ($q) => $q->whereHas('role', fn ($r) => $r->where('name', $filters['role'])))
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
        $active = User::active()->with('role')->get();

        return [
            'active' => $active->count(),
            'inactive' => User::where('is_active', false)->count(),
            ...collect(Role::LABELS)->map(fn ($l, $name) => $active->filter(fn ($u) => $u->role?->name === $name)->count())->all(),
        ];
    }

    /** @param  array<string, mixed>  $data  data tervalidasi */
    public function create(array $data): User
    {
        $user = User::create([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
            'role_id' => Role::named($data['role'])->id,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ])->load('role');

        AuditLog::record('user.created', 'pengguna', "Akun baru {$user->name} sebagai {$user->role->label}", self::subject($user),
            ['email' => $user->email, 'role' => $user->role->name],
            action: 'dibuat', new: ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->name, 'is_active' => (bool) $user->is_active]);

        return $user;
    }

    /** @param  array<string, mixed>  $data  data tervalidasi */
    public function update(User $user, array $data, ?User $actor): User
    {
        $user->loadMissing('role');
        $role = Role::named($data['role']);
        $active = (bool) ($data['is_active'] ?? $user->is_active);
        $this->guardChange($user, $role->name, $active, $actor);

        $before = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role?->name, 'is_active' => (bool) $user->is_active];

        DB::transaction(function () use ($user, $data, $role, $active) {
            $user->forceFill([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'role_id' => $role->id,
                'is_active' => $active,
            ]);
            if (filled($data['password'] ?? null)) {
                $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()]);
            }
            $user->save();
        });
        $user->load('role');

        $after = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->name, 'is_active' => (bool) $user->is_active];
        if ($changes = AuditLog::diff($before, $after, array_keys($after))) {
            AuditLog::record(isset($changes['role']) ? 'user.role_changed' : 'user.updated', 'pengguna',
                "Akun {$user->name} diubah: ".implode(', ', array_keys($changes)), self::subject($user), ['changes' => $changes],
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

    public function toggleActive(User $user, ?User $actor): User
    {
        $user->loadMissing('role');
        $this->guardChange($user, $user->role?->name, ! $user->is_active, $actor);

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
    private function guardChange(User $user, ?string $newRole, bool $newActive, ?User $actor): void
    {
        $errors = [];
        if ($actor && $actor->is($user)) {
            if (! $newActive) {
                $errors['is_active'] = 'Anda tidak bisa menonaktifkan akun sendiri.';
            }
            if ($user->role?->name === Role::ADMIN && $newRole !== Role::ADMIN) {
                $errors['role'] = 'Anda tidak bisa menurunkan peran akun sendiri.';
            }
        }
        $losesAdmin = $user->role?->name === Role::ADMIN && $user->is_active && ($newRole !== Role::ADMIN || ! $newActive);
        if (! isset($errors['role']) && $losesAdmin
            && User::active()->whereKeyNot($user->id)->whereHas('role', fn ($r) => $r->where('name', Role::ADMIN))->doesntExist()) {
            $errors['role'] = 'Harus ada minimal satu admin aktif.';
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
