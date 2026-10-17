<?php

namespace App\Livewire\Portal;

use App\Models\Member;
use App\Services\PortalCredentialService;
use App\Support\ImpersonationPolicy;
use App\Support\ImpersonationSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Portal → Akun saya: data akun (kode, nama, email, terakhir masuk) serta ganti kata sandi & PIN transaksi sendiri
 * lewat PortalCredentialService (keduanya dikonfirmasi kata sandi sekarang). Admin yang masuk sebagai anggota
 * (impersonasi) hanya melihat — tidak bisa mengganti kredensial anggota.
 */
#[Layout('components.layouts.portal')]
#[Title('Akun Saya')]
class Account extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $pin_password = '';

    public string $pin = '';

    public string $pin_confirmation = '';

    public ?string $saved = null;

    #[Computed]
    public function member(): Member
    {
        return Auth::guard('member')->user();
    }

    #[Computed]
    public function readOnly(): bool
    {
        return ImpersonationSession::impersonatingMember();
    }

    #[Computed]
    public function lock(): array
    {
        return app(PortalCredentialService::class)->lockStatus($this->member);
    }

    public function changePassword(PortalCredentialService $credentials): void
    {
        $this->run('password', fn () => $credentials->changePassword($this->member, $this->only('current_password', 'password', 'password_confirmation')),
            ['current_password', 'password', 'password_confirmation'], 'Kata sandi portal diganti. Perangkat lain yang masih masuk akan diminta masuk lagi.');
    }

    public function changePin(PortalCredentialService $credentials): void
    {
        $this->run('pin', fn () => $credentials->changePin($this->member, $this->only('pin_password', 'pin', 'pin_confirmation')),
            ['pin_password', 'pin', 'pin_confirmation'], 'PIN transaksi diganti. Pakai PIN baru untuk transaksi berikutnya.');
    }

    private function run(string $kind, callable $action, array $fields, string $message): void
    {
        $this->saved = null;
        $this->resetValidation();
        if ($this->readOnly) {
            $this->dispatch('toast', type: 'error', message: ImpersonationPolicy::MEMBER_READ_ONLY_MESSAGE);

            return;
        }
        try {
            $action();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            $this->reset($fields[0]); // kata sandi konfirmasi selalu dikosongkan
            unset($this->lock);

            return;
        }
        $this->reset($fields);
        unset($this->member, $this->lock);
        $this->saved = $kind;
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function render()
    {
        return view('livewire.portal.account');
    }
}
