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

    public function cancel(): void
    {
        $this->editing = false;
    }

    public function render()
    {
        return view('livewire.pengguna.index', ['menus' => Menu::ITEMS]);
    }
}
