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

    /** Form input angsuran (pinjaman berjalan/menunggak). */
    public bool $paying = false;

    public int|string|null $payAmount = '';

    public string $payDate = '';

    public string $payMethod = '';

    public string $payNote = '';

    /** Rujukan sesuai metode: no. transfer / bulan gaji (Y-m). */
    public string $payRef = '';

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

        return \App\Services\LoanScheduleGenerator::generate((int) round((float) $loan->principal), (int) round((float) $loan->interest), $loan->tenor_months, $this->disbursedOn);
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

    public function openPayment(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $loan = $this->loan();
        if (! in_array($loan->status, Loan::ACTIVE, true)) {
            return;
        }
        $next = $loan->installments()->where('status', '!=', 'dibayar')->orderBy('sequence')->first();
        $this->resetValidation();
        $this->payAmount = $next ? (int) round((float) $next->amount - (float) $next->paid_amount) : '';
        $this->payDate = today()->toDateString();
        $this->payMethod = $loan->payment_method;
        $this->payNote = '';
        $this->payRef = $loan->payment_method === 'potong_gaji' ? today()->format('Y-m') : '';
        $this->disbursing = false;
        $this->paying = true;
    }

    public function updatedPayMethod(): void
    {
        $this->payMethod = array_key_exists($this->payMethod, Loan::METHODS) ? $this->payMethod : $this->loan()->payment_method;
        $this->payRef = $this->payMethod === 'potong_gaji' ? today()->format('Y-m') : '';
        $this->resetValidation('payRef');
    }

    public function cancelPayment(): void
    {
        $this->paying = false;
    }

    /** Pratinjau alokasi nominal yang sedang diisi ke angsuran tertua. */
    #[Computed]
    public function paymentPreview(): array
    {
        $amount = is_numeric($this->payAmount) ? (int) $this->payAmount : 0;
        if (! $this->paying || $amount < 1) {
            return [];
        }

        return \App\Services\LoanPaymentService::allocate($this->loan()->installments()->get(), $amount);
    }

    public function recordPayment(): void
    {
        abort_unless(Gate::allows('kelola-pinjaman'), 403);
        $loan = $this->loan();
        $map = ['amount' => 'payAmount', 'paid_at' => 'payDate', 'payment_method' => 'payMethod', 'reference' => 'payRef', 'note' => 'payNote'];
        $rules = collect(\App\Services\LoanPaymentService::rules($loan))->mapWithKeys(fn ($r, $k) => [$map[$k] => $r])->all();
        $messages = collect(\App\Services\LoanPaymentService::messages($loan))->mapWithKeys(fn ($m, $k) => [str_replace(array_keys($map), array_values($map), $k) => $m])->all();
        $data = $this->validate($rules, $messages);

        try {
            $payment = app(\App\Services\LoanPaymentService::class)->record($loan, ['amount' => $data['payAmount'], 'paid_at' => $data['payDate'],
                'payment_method' => $data['payMethod'], 'reference' => $data['payRef'] ?? null, 'note' => $data['payNote'] ?? null], auth()->id());
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['loan'])) {
                $this->paying = false;
                $this->flash = $errors['loan'][0];
                unset($this->detail);

                return;
            }
            foreach ($errors as $key => $messages) {
                $this->addError($map[$key] ?? 'payAmount', $messages[0]);
            }

            return;
        }

        $loan->refresh();
        $this->paying = false;
        $this->flash = 'Angsuran '.\App\Support\Rupiah::format($payment->amount).' dicatat untuk angsuran ke-'.implode(', ', array_column($payment->allocations, 'sequence')).'. '
            .($loan->status === 'lunas' ? 'Pinjaman lunas.' : 'Sisa '.\App\Support\Rupiah::format($loan->outstanding).'.');
        unset($this->detail, $this->paymentPreview);
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
