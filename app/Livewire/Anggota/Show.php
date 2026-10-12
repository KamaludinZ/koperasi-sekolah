<?php

namespace App\Livewire\Anggota;

use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Profil anggota: identitas, saldo tabungan, dan ringkasan setor/tarik.
 * Tahap frontend: memakai data tiruan (MockData::members & ::savings).
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    public array $profile;

    public function mount(int $member): void
    {
        $found = MockData::findMember($member);
        abort_if(! $found, 404);

        $this->profile = $found;
    }

    #[Computed]
    public function savings(): array
    {
        return MockData::savings($this->profile['id']);
    }

    #[Computed]
    public function stats(): array
    {
        $all = collect($this->savings);
        $deposits = $all->where('type', 'setor');
        $withdrawals = $all->where('type', 'tarik');
        $last = $all->last();

        return [
            'deposit_total' => $deposits->sum('amount'),
            'deposit_count' => $deposits->count(),
            'withdraw_total' => $withdrawals->sum('amount'),
            'withdraw_count' => $withdrawals->count(),
            'last_at' => $last['created_at'] ?? null,
            'last_type' => $last['type'] ?? null,
        ];
    }

    public function render()
    {
        return view('livewire.anggota.show')->title($this->profile['name'].' · Anggota');
    }
}
