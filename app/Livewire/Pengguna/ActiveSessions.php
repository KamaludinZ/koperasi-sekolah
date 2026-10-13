<?php

namespace App\Livewire\Pengguna;

use App\Livewire\Concerns\NotifiesSave;
use App\Services\SessionService;
use App\Support\SessionPolicy;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Kelola Pengguna → Sesi aktif (Admin): petugas yang sedang masuk (aktif dalam batas menganggur) dengan
 * perangkat, peramban, IP, dan aktivitas terakhir; Admin dapat mengeluarkan sesi petugas lain dengan
 * alasan (mis. perangkat hilang) — tercatat di audit dan token "ingat saya" ikut dihapus.
 */
#[Layout('components.layouts.app')]
#[Title('Sesi Aktif')]
class ActiveSessions extends Component
{
    use NotifiesSave;

    public ?string $ending = null;

    public string $reason = '';

    #[Computed]
    public function sessions(): array
    {
        return app(SessionService::class)->allActive();
    }

    public function confirmEnd(string $id): void
    {
        $this->ending = collect($this->sessions)->contains('id', $id) ? $id : null;
        $this->reason = '';
        $this->resetValidation();
    }

    public function end(): void
    {
        $this->resetErrorBag();
        if (! $this->ending) {
            return;
        }
        try {
            $ok = app(SessionService::class)->forceEnd($this->ending, auth()->user(), $this->reason);
        } catch (ValidationException $e) {
            $this->addError('reason', $e->errors()['reason'][0]);

            return;
        }
        $this->ending = null;
        unset($this->sessions);
        $this->toast($ok ? 'success' : 'error', $ok ? 'Sesi diakhiri. Petugas harus masuk lagi di perangkat itu.' : 'Sesi sudah tidak ada.');
    }

    public function render()
    {
        return view('livewire.pengguna.active-sessions', ['idle' => SessionPolicy::idleMinutes()]);
    }
}
