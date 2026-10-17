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
 * Portal → Siapkan akun (/portal/mulai): wajib dibuka anggota saat pertama masuk atau setelah pengurus mengatur ulang
 * kata sandi/PIN (middleware portal mengarahkan ke sini). Anggota membuat kata sandi miliknya (mengganti kata sandi
 * sementara) dan/atau PIN transaksi 6 angka sendiri — pengurus tidak pernah mengetahui PIN.
 */
#[Layout('components.layouts.portal')]
#[Title('Siapkan Akun')]
class Setup extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    public string $pin = '';

    public string $pin_confirmation = '';

    public function mount()
    {
        if (! $this->member->needsPortalSetup()) {
            return $this->redirectRoute('portal', navigate: true);
        }

        return null;
    }

    #[Computed]
    public function member(): Member
    {
        return Auth::guard('member')->user();
    }

    #[Computed]
    public function needPassword(): bool
    {
        return $this->member->portal_password_changed_at === null;
    }

    #[Computed]
    public function needPin(): bool
    {
        return ! $this->member->hasPin();
    }

    public function save(PortalCredentialService $credentials)
    {
        $this->resetValidation();
        if (ImpersonationSession::impersonatingMember()) {
            $this->dispatch('toast', type: 'error', message: ImpersonationPolicy::MEMBER_READ_ONLY_MESSAGE);

            return null;
        }
        try {
            $credentials->completeSetup($this->member, $this->only('password', 'password_confirmation', 'pin', 'pin_confirmation'));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            $this->reset('password_confirmation', 'pin_confirmation');

            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => 'Akun siap. Kata sandi & PIN ini hanya Anda yang tahu — jangan bagikan ke siapa pun.']);

        return $this->redirectRoute('portal', navigate: true);
    }

    public function render()
    {
        return view('livewire.portal.setup');
    }
}
