<?php

namespace App\Livewire\Auth;

use App\Services\AuthService;
use App\Support\Menu;
use App\Support\MockData;
use Database\Seeders\UserSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman masuk petugas (admin, kasir, pengurus). Validasi akun lewat AuthService:
 * kata sandi terenkripsi, akun nonaktif ditolak, 5 percobaan gagal → dikunci 60 detik.
 * Setelah masuk diarahkan ke halaman tujuan atau halaman awal sesuai peran.
 */
#[Layout('components.layouts.guest')]
#[Title('Masuk Petugas')]
class Login extends Component
{
    public const MAX_ATTEMPTS = AuthService::MAX_ATTEMPTS;

    public const LOCK_SECONDS = AuthService::LOCK_SECONDS;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        if ($user = MockData::currentUser()) {
            $this->redirectRoute(Menu::homeRoute($user['role'], $user['permissions'] ?? null));
        }
    }

    public function login(AuthService $auth): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:100'],
        ], [
            'email.required' => 'Masukkan email akun Anda.',
            'email.email' => 'Format email belum benar.',
            'password.required' => 'Masukkan kata sandi.',
        ]);

        try {
            $user = $auth->login($this->email, $this->password, $this->remember, request());
        } catch (ValidationException $e) {
            $this->reset('password');
            $this->addError('email', $e->errors()['email'][0] ?? 'Gagal masuk.');

            return;
        }

        $this->redirectIntended(route(Menu::homeRoute($user->roleName(), $user->role?->permissions)));
    }

    public function render()
    {
        return view('livewire.auth.login', [
            'demoUsers' => app()->environment('local', 'testing')
                ? array_map(fn ($a) => [...$a], UserSeeder::ACCOUNTS)
                : [],
        ]);
    }
}
