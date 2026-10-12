<?php

namespace App\Livewire\Forms;

use App\Services\AuditLog;
use App\Support\MockData;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Form tambah/ubah akun petugas. Kata sandi wajib untuk akun baru,
 * opsional saat mengubah (diisi = atur ulang kata sandi).
 * Tahap frontend: disimpan ke akun tiruan (session).
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
        $emails = collect(MockData::users())->reject(fn ($u) => $u['id'] === $this->id)
            ->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', function ($attr, $value, $fail) use ($emails) {
                if (in_array(mb_strtolower(trim($value)), $emails, true)) {
                    $fail('Email ini sudah dipakai akun lain.');
                }
            }],
            'role' => ['required', Rule::in(array_keys(MockData::ROLES))],
            'is_active' => ['boolean'],
            'password' => [$this->id === null ? 'required' : 'nullable', 'string', 'min:8', 'max:100', 'confirmed'],
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'email.email' => 'Format email belum benar.',
            'password.min' => 'Minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    public function blank(): void
    {
        $this->reset();
        $this->resetValidation();
    }

    public function load(array $user): void
    {
        $this->reset();
        $this->resetValidation();
        $this->fill(collect($user)->only(['id', 'name', 'email', 'role', 'is_active'])->all());
    }

    /** @param  int|null  $currentUserId  akun yang sedang masuk (tidak boleh menonaktifkan/menurunkan diri sendiri) */
    public function save(?int $currentUserId): array
    {
        $data = $this->validate();

        $errors = [];
        if ($this->id !== null && $this->id === $currentUserId) {
            if (! $data['is_active']) {
                $errors['is_active'] = 'Anda tidak bisa menonaktifkan akun sendiri.';
            }
            if ($data['role'] !== 'admin' && (MockData::findUser($this->id)['role'] ?? null) === 'admin') {
                $errors['role'] = 'Anda tidak bisa menurunkan peran akun sendiri.';
            }
        }
        if (! isset($errors['role']) && $this->id !== null && (MockData::findUser($this->id)['role'] ?? null) === 'admin'
            && ($data['role'] !== 'admin' || ! $data['is_active']) && self::activeAdmins($this->id) === 0) {
            $errors['role'] = 'Harus ada minimal satu admin aktif.';
        }
        foreach ($errors as $field => $message) {
            $this->addError($field, $message);
        }
        if ($errors !== []) {
            return [];
        }

        $attributes = [
            'id' => $this->id,
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'role' => $data['role'],
            'is_active' => $data['is_active'],
        ];
        if (filled($data['password'])) {
            $attributes['password_hash'] = Hash::make($data['password']);
        }

        $before = $this->id ? MockData::findUser($this->id) : null;
        $saved = MockData::saveUser($attributes);
        $subject = ['type' => 'user', 'id' => $saved['id'], 'label' => "{$saved['name']} · {$saved['email']}"];

        if (! $before) {
            AuditLog::record('user.created', 'pengguna', "Akun baru {$saved['name']} sebagai ".MockData::ROLES[$saved['role']], $subject, ['email' => $saved['email'], 'role' => $saved['role']]);
        } else {
            $changes = AuditLog::diff($before, $saved, ['name', 'email', 'role', 'is_active']);
            if ($changes) {
                AuditLog::record(isset($changes['role']) ? 'user.role_changed' : 'user.updated', 'pengguna',
                    "Akun {$saved['name']} diubah: ".implode(', ', array_keys($changes)), $subject, ['changes' => $changes]);
            }
            if (filled($data['password'])) {
                AuditLog::record('user.password_reset', 'pengguna', "Kata sandi akun {$saved['name']} diatur ulang", $subject);
            }
        }

        return $saved;
    }

    /** Jumlah admin aktif selain akun tertentu. */
    public static function activeAdmins(?int $exceptId = null): int
    {
        return collect(MockData::users())
            ->filter(fn ($u) => $u['role'] === 'admin' && $u['is_active'] && $u['id'] !== $exceptId)
            ->count();
    }
}
