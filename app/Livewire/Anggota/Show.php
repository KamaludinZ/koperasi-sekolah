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

    /** Dialog setor/tarik: null (tertutup), 'setor', atau 'tarik'. */
    public ?string $mode = null;

    public ?int $amount = null;

    public string $note = '';

    public ?array $lastEntry = null;

    public function openSaving(string $mode): void
    {
        if (! in_array($mode, ['setor', 'tarik'], true) || ! $this->profile['is_active']) {
            return;
        }

        $this->resetValidation();
        $this->reset('amount', 'note', 'lastEntry');
        $this->mode = $mode;
    }

    public function closeSaving(): void
    {
        $this->mode = null;
    }

    public function saveSaving(): void
    {
        abort_unless($this->profile['is_active'] && in_array($this->mode, ['setor', 'tarik'], true), 403);

        $balance = $this->profile['balance'];
        $this->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:'.($this->mode === 'tarik' ? $balance : 10_000_000)],
            'note' => ['nullable', 'string', 'max:150'],
        ], [
            'amount.required' => 'Masukkan jumlah uang.',
            'amount.min' => 'Minimal Rp1.000.',
            'amount.max' => $this->mode === 'tarik'
                ? 'Penarikan melebihi saldo (Rp'.number_format($balance, 0, ',', '.').').'
                : 'Maksimal Rp10.000.000 per setoran.',
        ]);

        $this->lastEntry = MockData::recordSaving($this->profile['id'], $this->mode, $this->amount, trim($this->note) ?: null);
        $this->profile = MockData::findMember($this->profile['id']);
        $this->mode = null;
        unset($this->savings, $this->stats);
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
