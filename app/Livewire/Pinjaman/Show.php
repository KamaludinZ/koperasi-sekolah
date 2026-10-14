<?php

namespace App\Livewire\Pinjaman;

use App\Models\Loan;
use App\Services\LoanDisbursementService;
use App\Services\LoanService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    /** Form pencairan (untuk pinjaman berstatus disetujui). */
    public bool $disbursing = false;

    public string $disbursedOn = '';

    public int|string|null $amount = '';

    public string $proof = '';

    public ?string $flash = null;

    public function mount(Loan $loan): void
    {
        $this->loanId = $loan->id;
    }

    private function loan(): Loan
    {
        return Loan::findOrFail($this->loanId);
    }

    public function openDisburse(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $loan = $this->loan();
        if ($loan->status !== 'disetujui') {
            return;
        }
        $this->resetValidation();
        $this->disbursedOn = today()->toDateString();
        $this->amount = (int) round((float) $loan->principal);
        $this->proof = '';
        $this->disbursing = true;
    }

    public function cancelDisburse(): void
    {
        $this->disbursing = false;
    }

    /** Pratinjau jadwal dari tanggal yang sedang diisi. */
    #[Computed]
    public function schedulePreview(): array
    {
        $loan = $this->loan();
        if ($loan->status !== 'disetujui' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->disbursedOn)) {
            return [];
        }

        return LoanDisbursementService::schedule($loan->totalDue(), $loan->tenor_months, $this->disbursedOn);
    }

    public function disburse(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $loan = $this->loan();
        $data = $this->validate(
            collect(LoanDisbursementService::rules($loan))->mapWithKeys(fn ($r, $k) => [['disbursed_on' => 'disbursedOn', 'amount' => 'amount', 'proof' => 'proof'][$k] => $r])->all(),
            collect(LoanDisbursementService::messages($loan))->mapWithKeys(fn ($m, $k) => [str_replace(['disbursed_on', 'amount.', 'proof.'], ['disbursedOn', 'amount.', 'proof.'], $k) => $m])->all(),
        );
        try {
            $loan = app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => $data['disbursedOn'], 'amount' => $data['amount'], 'proof' => $data['proof'] ?? null], auth()->id());
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['loan'])) { // status berubah di tempat lain: tutup form & tampilkan alasannya
                $this->disbursing = false;
                $this->flash = $errors['loan'][0];
                unset($this->detail);

                return;
            }
            $this->addError('amount', collect($errors)->flatten()->first());

            return;
        }
        $this->disbursing = false;
        $this->flash = "Dana {$loan->code} dicairkan. {$loan->tenor_months} angsuran terjadwal mulai ".\App\Support\Tanggal::format($loan->installments()->min('due_date')).'.';
        unset($this->detail, $this->schedulePreview);
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
