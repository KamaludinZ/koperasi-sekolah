<?php

namespace App\Livewire\Portal;

use App\Services\PortalAuthService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Portal anggota → Masuk: kode anggota/email + kata sandi (PIN hanya untuk verifikasi transaksi). */
#[Layout('components.layouts.portal')]
#[Title('Masuk Anggota')]
class Login extends Component
{
    public string $identifier = '';

    public string $password = '';

    public function login()
    {
        $this->validate(
            ['identifier' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'max:100']],
            ['identifier.required' => 'Masukkan kode anggota atau email.', 'password.required' => 'Masukkan kata sandi.'],
        );

        try {
            app(PortalAuthService::class)->attempt($this->identifier, $this->password, (string) request()->ip());
        } catch (ValidationException $e) {
            $this->reset('password');
            throw $e;
        }
        session()->regenerate();

        // url.intended dipakai bersama login petugas: ikuti hanya bila menuju halaman portal.
        $intended = (string) session('url.intended', '');
        $portal = rtrim(route('portal'), '/');
        if ($intended === $portal || str_starts_with($intended, $portal.'/')) {
            session()->forget('url.intended');

            return $this->redirect($intended, navigate: true);
        }

        return $this->redirect(route('portal'), navigate: true);
    }

    public function render()
    {
        return view('livewire.portal.login');
    }
}
