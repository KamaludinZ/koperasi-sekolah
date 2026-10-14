<?php

namespace App\Livewire\Pinjaman;

use App\Models\Loan;
use App\Services\LoanService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pinjaman → Rincian (Admin & Pengurus): data pinjaman & anggota, pencairan, serta jadwal angsuran
 * lengkap dengan status bayar, jatuh tempo, dan ringkasan sisa.
 */
#[Layout('components.layouts.app')]
#[Title('Rincian Pinjaman')]
class Show extends Component
{
    #[Locked]
    public int $loanId;

    public function mount(Loan $loan): void
    {
        $this->loanId = $loan->id;
    }

    #[Computed]
    public function detail(): array
    {
        return app(LoanService::class)->detail(Loan::findOrFail($this->loanId));
    }

    public function render()
    {
        return view('livewire.pinjaman.show');
    }
}
