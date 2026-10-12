<?php

namespace App\Livewire\Forms;

use App\Models\User;
use App\Services\UserService;
use Livewire\Form;

/**
 * Form tambah/ubah akun petugas (aturan sama dengan endpoint JSON, UserService::rules).
 * Kata sandi wajib untuk akun baru; saat mengubah, diisi = atur ulang kata sandi.
 */
class UserForm extends Form
{
    public ?int $id = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'kasir';

    public bool $is_active = true;

    public string $password = '';

    public string $password_confirmation = '';

    protected function rules(): array
    {
        return UserService::rules($this->id);
    }

    protected function messages(): array
    {
        return UserService::messages();
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
        $this->fill(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role?->name ?? 'kasir', 'is_active' => (bool) $user->is_active]);
    }

    public function save(?User $actor): User
    {
        $data = $this->validate();
        $service = app(UserService::class);

        return $this->id === null
            ? $service->create($data)
            : $service->update(User::findOrFail($this->id), $data, $actor);
    }
}
