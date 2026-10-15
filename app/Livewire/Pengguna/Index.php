<?php

namespace App\Livewire\Pengguna;

use App\Livewire\Forms\UserForm;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\Menu;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kelola pengguna (khusus admin): daftar akun petugas, peran & status, tambah/ubah akun,
 * atur ulang kata sandi, aktif/nonaktifkan, dan atur hak menu tiap peran. Data dari UserService.
 */
#[Layout('components.layouts.app')]
#[Title('Kelola Pengguna')]
class Index extends Component
{
    public UserForm $form;

    public bool $editing = false;

    public ?string $flash = null;

    public ?string $error = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'peran', except: '')]
    public string $role = '';

    /** Editor hak akses: [roleName => [menuKey, ...]] */
    public array $permissions = [];

    public ?string $permissionsRole = null;

    public function updatedRole(): void
    {
        $this->role = array_key_exists($this->role, Role::LABELS) || $this->role === 'tanpa' ? $this->role : '';
    }

    #[Computed]
    public function users()
    {
        return app(UserService::class)->query(['search' => $this->search, 'role' => $this->role])->get();
    }

    #[Computed]
    public function stats(): array
    {
        return app(UserService::class)->stats();
    }

    #[Computed]
    public function roles()
    {
        return Role::orderBy('id')->get();
    }

    public function currentUserId(): ?int
    {
        return auth()->id();
    }

    public function create(): void
    {
        $this->form->blank();
        $this->reset('flash', 'error');
        $this->editing = true;
    }

    public function edit(int $userId): void
    {
        if ($user = User::with('role', 'roles')->find($userId)) {
            $this->form->load($user);
            $this->reset('flash', 'error');
            $this->editing = true;
        }
    }

    public function save(): void
    {
        $isNew = $this->form->id === null;
        if (! $isNew && ($current = User::with('role', 'roles')->find($this->form->id)) && $current->roleNames() !== $this->form->rolesSnapshot) {
            $this->form->roles = $this->form->rolesSnapshot = $current->roleNames();
            $this->addError('form.roles', 'Peran akun ini baru saja diubah petugas lain. Pilihan dimuat ulang sesuai yang tersimpan — periksa lalu simpan lagi.');

            return;
        }

        try {
            $user = $this->form->save(auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(str_starts_with($field, 'form.') ? $field : 'form.'.$field, $messages[0]);
            }

            return;
        }

        $this->editing = false;
        $labels = implode(' + ', array_map(fn ($n) => Role::LABELS[$n] ?? $n, $user->roleNames()));
        $this->flash = $isNew ? "Akun {$user->name} dibuat sebagai {$labels}." : "Akun {$user->name} diperbarui ({$labels}).";
        unset($this->users, $this->stats);
    }

    public function toggleActive(int $userId): void
    {
        $this->reset('flash', 'error');
        $user = User::with('role')->find($userId);
        if (! $user) {
            return;
        }

        try {
            $user = app(UserService::class)->toggleActive($user, auth()->user());
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }

        $this->flash = $user->is_active ? "{$user->name} diaktifkan kembali." : "{$user->name} dinonaktifkan dan tidak bisa masuk lagi.";
        unset($this->users, $this->stats);
    }

    public function editPermissions(string $roleName): void
    {
        $role = Role::named($roleName);
        $this->permissionsRole = $role->name;
        $this->permissions[$role->name] = $role->permissions ?? [];
        $this->resetValidation('permissions');
    }

    public function savePermissions(): void
    {
        $role = Role::named($this->permissionsRole);

        try {
            app(UserService::class)->updatePermissions($role, $this->permissions[$role->name] ?? []);
        } catch (ValidationException $e) {
            $this->addError('permissions', $e->errors()['permissions'][0]);

            return;
        }

        $this->permissionsRole = null;
        $this->flash = "Hak akses peran {$role->label} disimpan. Berlaku pada halaman berikutnya yang dibuka petugas.";
        unset($this->roles);
    }

    public function cancelPermissions(): void
    {
        $this->permissionsRole = null;
    }

    /** Form "Ubah peran": akun yang sedang diubah perannya & peran yang dicentang. */
    public ?int $roleUserId = null;

    /** @var list<string> */
    public array $roleChoice = [];

    /** @var list<string> peran tersimpan saat form dibuka (deteksi perubahan oleh petugas lain) */
    #[\Livewire\Attributes\Locked]
    public array $roleSnapshot = [];

    public function editRoles(int $userId): void
    {
        $user = User::with('role', 'roles')->find($userId);
        if (! $user) {
            return;
        }
        $this->roleUserId = $user->id;
        $this->roleChoice = $user->roleNames();
        $this->roleSnapshot = $this->roleChoice;
        $this->reset('flash', 'error');
        $this->resetValidation('roleChoice');
    }

    /** Pratinjau perubahan: peran ditambah/dicabut & menu yang bertambah/hilang. */
    #[Computed]
    public function roleDiff(): array
    {
        $user = $this->roleUserId ? User::with('role', 'roles')->find($this->roleUserId) : null;
        if (! $user) {
            return ['user' => null, 'added' => [], 'removed' => [], 'menus_added' => [], 'menus_removed' => []];
        }
        $choice = array_values(array_intersect(array_keys(Role::LABELS), $this->roleChoice));
        $before = $user->roleNames();
        $menus = fn (array $names) => $this->roles->whereIn('name', $names)->flatMap(fn ($r) => $r->permissions ?? [])->unique()->values()->all();
        [$mb, $ma] = [$menus($before), $menus($choice)];

        return ['user' => $user, 'before' => $before, 'added' => array_values(array_diff($choice, $before)), 'removed' => array_values(array_diff($before, $choice)),
            'menus_added' => array_values(array_diff($ma, $mb)), 'menus_removed' => array_values(array_diff($mb, $ma)), 'menus' => $ma];
    }

    public function saveRoles(): void
    {
        $user = User::with('role', 'roles')->find($this->roleUserId);
        if (! $user) {
            $this->roleUserId = null;

            return;
        }
        $this->validate(['roleChoice' => ['required', 'array', 'min:1'], 'roleChoice.*' => ['string', 'distinct', \Illuminate\Validation\Rule::in(array_keys(Role::LABELS))]],
            ['roleChoice.required' => UserService::MSG_MIN_ROLE, 'roleChoice.min' => UserService::MSG_MIN_ROLE, 'roleChoice.*.in' => 'Peran tidak dikenal.']);
        if ($user->roleNames() !== $this->roleSnapshot) { // diubah petugas lain sejak form dibuka
            $this->roleChoice = $this->roleSnapshot = $user->roleNames();
            $this->addError('roleChoice', 'Peran akun ini baru saja diubah petugas lain. Pilihan dimuat ulang sesuai yang tersimpan — periksa lalu simpan lagi.');
            unset($this->roleDiff, $this->users);

            return;
        }
        try {
            $r = app(UserService::class)->updateRoles($user, $this->roleChoice, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('roleChoice', collect($e->errors())->flatten()->first());

            return;
        }
        $this->roleUserId = null;
        $label = fn (array $n) => implode(', ', array_map(fn ($x) => Role::LABELS[$x] ?? $x, $n));
        $this->flash = $r['added'] === [] && $r['removed'] === [] ? "Peran {$user->name} tidak berubah."
            : "Peran {$user->name} diperbarui".($r['added'] ? ': + '.$label($r['added']) : '').($r['removed'] ? ($r['added'] ? ';' : ':').' − '.$label($r['removed']) : '').'.';
        unset($this->users, $this->stats);
    }

    /** Aksi cepat di daftar: tambah satu peran. */
    public function addRole(int $userId, string $role): void
    {
        $this->quickRole($userId, $role, true);
    }

    /** Aksi cepat di daftar: cabut satu peran. */
    public function removeRole(int $userId, string $role): void
    {
        $this->quickRole($userId, $role, false);
    }

    private function quickRole(int $userId, string $role, bool $add): void
    {
        $this->reset('flash', 'error');
        $user = User::with('role', 'roles')->find($userId);
        if (! $user || ! array_key_exists($role, Role::LABELS)) {
            $this->error = 'Akun atau peran tidak dikenal.';

            return;
        }
        if ($add && in_array($role, $user->roleNames(), true)) {
            $this->flash = "{$user->name} sudah memegang peran ".Role::LABELS[$role].'.';

            return;
        }
        try {
            $svc = app(UserService::class);
            $add ? $svc->addRole($user, $role, auth()->user()) : $svc->removeRole($user, $role, auth()->user());
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();

            return;
        }
        $this->flash = 'Peran '.Role::LABELS[$role].($add ? ' ditambahkan ke ' : ' dicabut dari ').$user->name.'.';
        unset($this->users, $this->stats);
    }

    public function cancelRoles(): void
    {
        $this->roleUserId = null;
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    public function render()
    {
        return view('livewire.pengguna.index', ['menus' => Menu::ITEMS]);
    }
}
