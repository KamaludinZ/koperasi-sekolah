<?php

namespace App\Livewire\Forms;

use App\Models\User;
use App\Services\UserService;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * Form tambah/ubah akun petugas (aturan sama dengan endpoint JSON, UserService::rules). Satu akun bisa memegang
 * beberapa peran sekaligus (kotak centang, minimal satu).
 * Kata sandi wajib untuk akun baru; saat mengubah, diisi = atur ulang kata sandi.
 */
class UserForm extends Form
{
    public ?int $id = null;

    public string $name = '';

    public string $email = '';

    /** @var list<string> peran yang dicentang (minimal satu) */
    public array $roles = ['kasir'];

    /** @var list<string> peran tersimpan saat form dibuka (kosong untuk akun baru) */
    #[Locked]
    public array $rolesSnapshot = [];

    public bool $is_active = true;

    public string $password = '';

    public string $password_confirmation = '';

    protected function rules(): array
    {
        $rules = UserService::rules($this->id);
        unset($rules['role']); // form memakai daftar peran

        return ['roles' => ['required', 'array', 'min:1']] + $rules;
    }

    protected function messages(): array
    {
        return ['roles.required' => UserService::MSG_MIN_ROLE, 'roles.min' => UserService::MSG_MIN_ROLE] + UserService::messages();
    }

    public function blank(): void
    {
        $this->reset();
        $this->resetValidation();
    }

    public function load(User $user): void
    {
        $this->reset();
        $this->resetValidation();
        $this->fill(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'roles' => $user->roleNames(), 'rolesSnapshot' => $user->roleNames(), 'is_active' => (bool) $user->is_active]);
    }

    public function save(?User $actor): User
    {
        $data = $this->validate();
        $service = app(UserService::class);

        return $this->id === null
            ? $service->create($data, $actor)
            : $service->update(User::findOrFail($this->id), $data, $actor);
    }
}
