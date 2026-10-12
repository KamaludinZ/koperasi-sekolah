<?php

namespace App\Livewire\Auth;

use App\Support\Menu;
use App\Support\MockData;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman masuk petugas (admin, kasir, pengurus).
 * Tahap frontend: dicocokkan ke akun tiruan (MockData::users) dan disimpan di session;
 * batas 5 percobaan gagal lalu dikunci 60 detik.
 */
#[Layout('components.layouts.guest')]
#[Title('Masuk Petugas')]
class Login extends Component
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 60;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        if ($user = MockData::currentUser()) {
            $this->redirectRoute(Menu::homeRoute($user['role']));
        }
    }

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:100'],
        ], [
            'email.required' => 'Masukkan email akun Anda.',
            'email.email' => 'Format email belum benar.',
            'password.required' => 'Masukkan kata sandi.',
        ]);

        if ($wait = $this->lockedFor()) {
            $this->addError('email', "Terlalu banyak percobaan. Coba lagi dalam {$wait} detik.");

            return;
        }

        $user = MockData::attempt($this->email, $this->password);

        if (! $user) {
            $attempts = session('mock.login_attempts', 0) + 1;
            session(['mock.login_attempts' => $attempts]);
            if ($attempts >= self::MAX_ATTEMPTS) {
                session(['mock.login_locked_until' => now()->addSeconds(self::LOCK_SECONDS)->timestamp]);
            }
            $this->reset('password');
            $this->addError('email', 'Email atau kata sandi salah.');

            return;
        }

        if (! $user['is_active']) {
            $this->reset('password');
            $this->addError('email', 'Akun ini dinonaktifkan. Hubungi admin koperasi.');

            return;
        }

        session()->forget(['mock.login_attempts', 'mock.login_locked_until']);
        session()->regenerate();
        $user = MockData::saveUser(['id' => $user['id'], 'last_login_at' => now()->toDateTimeString()]);
        session(['mock.user' => $user]);

        $this->redirectIntended(route(Menu::homeRoute($user['role'])));
    }

    /** Sisa detik penguncian, 0 bila tidak terkunci. */
    public function lockedFor(): int
    {
        $until = session('mock.login_locked_until');
        if (! $until || $until <= now()->timestamp) {
            if ($until) {
                session()->forget(['mock.login_attempts', 'mock.login_locked_until']);
            }

            return 0;
        }

        return $until - now()->timestamp;
    }

    public function render()
    {
        return view('livewire.auth.login', [
            'demoUsers' => app()->environment('local', 'testing') ? MockData::users() : [],
        ]);
    }
}
