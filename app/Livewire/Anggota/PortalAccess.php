<?php

namespace App\Livewire\Anggota;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Member;
use App\Models\Role;
use App\Services\PortalAccountService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kartu "Akses portal" di profil anggota (Admin & Pengurus): aktifkan / atur ulang kata sandi & PIN,
 * cabut akses. Kata sandi & PIN acak ditampilkan sekali untuk diserahkan ke anggota.
 */
class PortalAccess extends Component
{
    use NotifiesSave;

    #[Locked]
    public int $memberId;

    public string $email = '';

    /** Rahasia yang baru dibuat (tampil sekali, tidak disimpan di state Livewire setelah ditutup). */
    public ?array $issued = null;

    public bool $editing = false;

    public function mount(int $memberId): void
    {
        $this->memberId = $memberId;
        $this->email = (string) Member::find($memberId)?->email;
    }

    #[Computed]
    public function member(): Member
    {
        return Member::findOrFail($this->memberId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::ADMIN, Role::PENGURUS);
    }

    public function enable(): void
    {
        abort_unless($this->canManage, 403);
        $this->resetErrorBag();
        try {
            $secrets = app(PortalAccountService::class)->enable($this->member, $this->email, by: auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $k => $m) {
                $this->addError($k === 'member' ? 'email' : $k, $m[0]);
            }

            return;
        }
        $this->issued = $secrets + ['code' => $this->member->member_code];
        $this->editing = false;
        unset($this->member);
    }

    public function resetPin(): void
    {
        abort_unless($this->canManage, 403);
        try {
            $pin = app(PortalAccountService::class)->resetPin($this->member);
        } catch (ValidationException $e) {
            $this->toast('error', collect($e->errors())->flatten()->first());

            return;
        }
        $this->issued = ['password' => null, 'pin' => $pin, 'code' => $this->member->member_code];
        unset($this->member);
    }

    public function disable(): void
    {
        abort_unless($this->canManage, 403);
        app(PortalAccountService::class)->disable($this->member);
        $this->issued = null;
        unset($this->member);
        $this->toast('success', 'Akses portal dicabut.');
    }

    public function dismiss(): void
    {
        $this->issued = null;
    }

    public function render()
    {
        return view('livewire.anggota.portal-access');
    }
}
