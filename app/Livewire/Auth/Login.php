<?php

namespace App\Livewire\Auth;

use App\Support\Petugas;
use App\Services\AuthService;
use App\Services\LoginLockoutService;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Halaman masuk petugas (admin, kasir, pengurus). Validasi akun lewat AuthService:
 * kata sandi terenkripsi, akun nonaktif ditolak, kunci bertingkat setelah gagal berulang
 * (LoginLockoutService: 1 menit → 15 menit → sampai dibuka Admin). Menampilkan sisa percobaan
 * sebelum dikunci dan panel kunci dengan hitung mundur (`lock.manual` = hubungi Admin).
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

    /** Sisa percobaan sebelum dikunci; ditampilkan bila tinggal sedikit. */
    public ?int $attemptsLeft = null;

    /** @var array{seconds: ?int, manual: bool}|null kunci aktif untuk email ini */
    public ?array $lock = null;

    /** Peringatan sisa percobaan muncul bila tinggal sebanyak ini atau kurang. */
    public const WARN_AT = 2;

    public function mount(): void
    {
        if ($user = Petugas::current()) {
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
            $this->refreshLock();
            if ($this->lock === null) {
                $this->addError('email', $e->errors()['email'][0] ?? 'Gagal masuk.');
            }

            return;
        }

        $this->redirectIntended(route(Menu::homeRoute($user->roleName(), $user->permissions())));
    }

    /** Baca status kunci & sisa percobaan untuk email ini. */
    public function refreshLock(): void
    {
        $status = app(LoginLockoutService::class)->status($this->email);
        $this->lock = $status['locked'] ? ['seconds' => $status['seconds'], 'manual' => $status['manual']] : null;
        $this->attemptsLeft = $status['locked'] ? 0 : $status['attempts_left'];
    }

    /** Dipanggil hitung mundur saat kunci sementara habis. */
    public function lockExpired(): void
    {
        $this->refreshLock();
        $this->resetErrorBag();
    }

    public function updatedEmail(): void
    {
        $this->lock = null;
        $this->attemptsLeft = null;
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
