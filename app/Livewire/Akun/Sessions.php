<?php

namespace App\Livewire\Akun;

use App\Services\SessionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sesi aktif akun yang sedang masuk: perangkat, IP, aktivitas terakhir;
 * akhiri satu sesi lain atau keluar dari semua perangkat lain (wajib kata sandi).
 */
#[Layout('components.layouts.app')]
#[Title('Sesi Aktif')]
class Sessions extends Component
{
    public string $password = '';

    public ?string $flash = null;

    #[Computed]
    public function sessions(): array
    {
        return auth()->user() ? app(SessionService::class)->list(auth()->user()) : [];
    }

    public function revoke(string $id): void
    {
        try {
            $ok = app(SessionService::class)->revoke(auth()->user(), $id);
            $this->flash = $ok ? 'Sesi di perangkat itu sudah diakhiri.' : 'Sesi sudah tidak ada.';
        } catch (ValidationException $e) {
            $this->flash = $e->errors()['session'][0];
        }
        unset($this->sessions);
    }

    public function revokeOthers(): void
    {
        $this->validate(['password' => ['required', 'string']], ['password.required' => 'Masukkan kata sandi Anda untuk konfirmasi.']);

        try {
            $count = app(SessionService::class)->revokeOthers(auth()->user(), $this->password);
        } catch (ValidationException $e) {
            $this->addError('password', $e->errors()['password'][0]);

            return;
        }

        $this->reset('password');
        $this->flash = $count > 0 ? "{$count} sesi di perangkat lain sudah diakhiri." : 'Tidak ada sesi lain yang aktif.';
        unset($this->sessions);
    }

    public function render()
    {
        return view('livewire.akun.sessions', ['idle' => \App\Support\SessionPolicy::idleMinutes()]);
    }
}
