<?php

namespace App\Livewire\Pinjaman;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Services\LoanService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pinjaman → Pencairan (Admin & Pengurus): pengajuan yang sudah disetujui & siap dicairkan,
 * serta riwayat pencairan dana terbaru (tanggal, nominal, bukti serah terima).
 */
#[Layout('components.layouts.app')]
#[Title('Pencairan Pinjaman')]
class Disbursements extends Component
{
    public const RECENT = 20;

    /** @return list<array> pinjaman disetujui yang belum dicairkan, terlama diputuskan dulu */
    #[Computed]
    public function ready(): array
    {
        return Loan::with('member:id,member_code,name,class,type,deleted_at')->where('status', 'disetujui')
            ->orderBy('decided_at')->orderBy('id')->get()
            ->map(fn (Loan $l) => LoanService::toArray($l) + ['decided_at' => $l->decided_at?->toDateTimeString()])->all();
    }

    /** @return list<array> pencairan terbaru */
    #[Computed]
    public function recent(): array
    {
        return LoanDisbursement::with(['loan:id,code,member_id,status', 'loan.member:id,member_code,name,deleted_at', 'user:id,name'])
            ->orderByDesc('disbursed_on')->orderByDesc('id')->limit(self::RECENT)->get()
            ->map(fn (LoanDisbursement $d) => [
                'id' => $d->id, 'loan_id' => $d->loan_id, 'code' => $d->loan?->code, 'member' => $d->loan?->member?->name,
                'status' => $d->loan?->status, 'disbursed_on' => $d->disbursed_on->toDateString(), 'amount' => (int) round((float) $d->amount),
                'proof' => $d->proof, 'has_file' => filled($d->proof_file), 'user' => $d->user?->name,
            ])->all();
    }

    public function render()
    {
        return view('livewire.pinjaman.disbursements');
    }
}
