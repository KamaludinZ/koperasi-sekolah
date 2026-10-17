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
 * Kartu "Akses portal" di profil anggota (Admin & Pengurus): aktifkan akses dengan kata sandi sementara (tampil
 * sekali untuk diserahkan), bantu atur ulang kata sandi atau PIN, ubah email, dan cabut akses. Kata sandi tetap &
 * PIN dibuat anggota sendiri saat masuk portal (pengurus tidak pernah tahu PIN).
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

    /** Aktifkan akses, atau atur ulang kata sandi bila sudah aktif — selalu kata sandi SEMENTARA yang ditampilkan sekali. */
    public function enable(): void
    {
        abort_unless($this->canManage, 403);
        $this->resetErrorBag();
        $active = $this->member->hasPortalAccess();
        try {
            $secrets = app(PortalAccountService::class)->enable($this->member, $active ? null : $this->email, by: auth()->user());
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

    /** PIN dihapus; anggota membuat PIN baru sendiri saat masuk berikutnya (pengurus tidak pernah tahu PIN). */
    public function resetPin(): void
    {
        abort_unless($this->canManage, 403);
        try {
            app(PortalAccountService::class)->resetPin($this->member);
        } catch (ValidationException $e) {
            $this->toast('error', collect($e->errors())->flatten()->first());

            return;
        }
        unset($this->member);
        $this->toast('success', 'PIN diatur ulang. Anggota membuat PIN baru sendiri saat masuk portal berikutnya.');
    }

    public function saveEmail(): void
    {
        abort_unless($this->canManage, 403);
        $this->resetErrorBag();
        try {
            app(PortalAccountService::class)->updateEmail($this->member, $this->email);
        } catch (ValidationException $e) {
            $this->addError('email', collect($e->errors())->flatten()->first());

            return;
        }
        $this->editing = false;
        unset($this->member);
        $this->email = (string) $this->member->email;
        $this->toast('success', 'Email portal disimpan.');
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
