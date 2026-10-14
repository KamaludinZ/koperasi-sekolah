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
use Livewire\WithFileUploads;

/**
 * Pinjaman → Rincian (Admin & Pengurus): data pinjaman & anggota, pencairan, serta jadwal angsuran
 * lengkap dengan status bayar, jatuh tempo, dan ringkasan sisa.
 */
#[Layout('components.layouts.app')]
#[Title('Rincian Pinjaman')]
class Show extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $loanId;

    /** Form pencairan (untuk pinjaman berstatus disetujui). */
    public bool $disbursing = false;

    public string $disbursedOn = '';

    public int|string|null $amount = '';

    public string $proof = '';

    /** Berkas bukti serah terima (opsional): foto/scan atau PDF. */
    public $proofFile = null;

    /** Lampirkan/ganti bukti untuk pencairan yang sudah tercatat. */
    #[Locked]
    public ?int $attachingId = null;

    public string $attachProof = '';

    public $attachFile = null;

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
        $this->proofFile = null;
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
        $data = $this->validate([
            'disbursedOn' => LoanDisbursementService::rules($loan)['disbursed_on'],
            'amount' => LoanDisbursementService::rules($loan)['amount'],
            'proof' => LoanDisbursementService::rules($loan)['proof'],
            'proofFile' => \App\Services\UploadPolicy::proofRules(),
        ], collect(LoanDisbursementService::messages($loan))->mapWithKeys(fn ($m, $k) => [str_replace('disbursed_on', 'disbursedOn', $k) => $m])->all()
            + \App\Services\UploadPolicy::proofMessages('proofFile'));

        $file = $this->proofFile ? LoanDisbursementService::storeProof($this->proofFile) : null;
        try {
            $loan = app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => $data['disbursedOn'], 'amount' => $data['amount'], 'proof' => $data['proof'] ?? null], auth()->id(), $file);
        } catch (\Throwable $e) {
            if ($file) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($file['proof_file']); // pencairan batal → berkas tidak tertinggal
            }
            if (! $e instanceof ValidationException) {
                throw $e;
            }
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
        $this->proofFile = null;
        $this->disbursing = false;
        $this->flash = "Dana {$loan->code} dicairkan. {$loan->tenor_months} angsuran terjadwal mulai ".\App\Support\Tanggal::format($loan->installments()->min('due_date')).'.';
        unset($this->detail, $this->schedulePreview);
    }

    #[Computed]
    public function detail(): array
    {
        return app(LoanService::class)->detail(Loan::findOrFail($this->loanId));
    }

    public function openAttach(int $disbursementId): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $d = \App\Models\LoanDisbursement::where('loan_id', $this->loanId)->find($disbursementId);
        if (! $d) {
            return;
        }
        $this->attachingId = $d->id;
        $this->attachProof = $d->proof ?? '';
        $this->attachFile = null;
        $this->resetValidation(['attachProof', 'attachFile']);
    }

    public function cancelAttach(): void
    {
        $this->attachingId = null;
    }

    public function saveAttach(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $d = $this->attachingId ? \App\Models\LoanDisbursement::where('loan_id', $this->loanId)->find($this->attachingId) : null;
        if (! $d) {
            $this->attachingId = null;

            return;
        }
        $this->validate(['attachProof' => ['nullable', 'string', 'max:200'], 'attachFile' => \App\Services\UploadPolicy::proofRules()],
            ['attachProof.max' => 'Keterangan bukti maksimal 200 karakter.'] + \App\Services\UploadPolicy::proofMessages('attachFile'));
        $file = $this->attachFile ? LoanDisbursementService::storeProof($this->attachFile) : null;
        app(LoanDisbursementService::class)->attachProof($d, $this->attachProof, $file);

        $this->attachingId = null;
        $this->attachFile = null;
        $this->flash = 'Bukti pencairan diperbarui.';
        unset($this->detail);
    }

    public function render()
    {
        return view('livewire.pinjaman.show');
    }
}
