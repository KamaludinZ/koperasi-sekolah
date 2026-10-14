<?php

namespace App\Livewire\Forms;

use App\Services\LoanApplicationService;
use Livewire\Form;

/** Form pengajuan pinjaman (anggota, nominal, bunga, tenor, metode, keperluan). Aturan dari LoanApplicationService. */
class LoanApplicationForm extends Form
{
    /** Diisi saat mengubah pengajuan (null = pengajuan baru). */
    #[\Livewire\Attributes\Locked]
    public ?int $loan_id = null;

    public ?int $member_id = null;

    public int|string|null $principal = '';

    public int|string|null $interest = '';

    public int|string|null $tenor_months = '';

    /** Cara mengisi bunga: nominal rupiah langsung, atau persen dari pokok (dihitung jadi nominal saat simpan). */
    public string $interest_mode = 'nominal';

    public int|float|string|null $interest_rate = '';

    public string $payment_method = 'potong_gaji';

    public ?string $purpose = '';

    public string $applied_at = '';

    public const TENOR_PRESETS = [3, 6, 10, 12, 24];

    protected function rules(): array
    {
        $base = LoanApplicationService::rules();
        if ($this->loan_id !== null) {
            unset($base['member_id']); // anggota tidak bisa diganti saat mengubah
            $base['applied_at'] = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
        }

        return [...$base,
            'interest_mode' => ['required', 'in:nominal,persen'],
            'interest_rate' => ['exclude_unless:interest_mode,persen', 'required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ];
    }

    /** Bunga persen → nominal (dibulatkan ke rupiah terdekat) sebelum divalidasi. */
    public function syncInterest(): void
    {
        if ($this->interest_mode === 'persen' && is_numeric($this->interest_rate) && is_numeric($this->principal)) {
            $this->interest = (int) round((int) $this->principal * (float) $this->interest_rate / 100);
        }
    }

    /** @return array data tervalidasi untuk LoanApplicationService (tanpa field bantu). */
    public function validated(): array
    {
        $this->syncInterest();
        $data = $this->validate();
        unset($data['interest_mode'], $data['interest_rate']);
        if ($error = LoanApplicationService::monthlyError($data)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['form.tenor_months' => $error]);
        }

        return $data;
    }

    protected function messages(): array
    {
        return LoanApplicationService::messages() + [
            'interest_rate.required' => 'Isi persen bunga.',
            'interest_rate.numeric' => 'Persen bunga harus berupa angka.',
            'interest_rate.min' => 'Persen bunga tidak boleh negatif.',
            'interest_rate.max' => 'Persen bunga maksimal 100%.',
            'interest_rate.decimal' => 'Persen bunga maksimal 2 angka di belakang koma.',
        ];
    }

    public function blank(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->applied_at = today()->toDateString();
        $this->interest = 0;
    }

    public function load(\App\Models\Loan $loan): void
    {
        $this->blank();
        $this->loan_id = $loan->id;
        $this->member_id = $loan->member_id;
        $this->principal = (int) round((float) $loan->principal);
        $this->interest = (int) round((float) $loan->interest);
        $this->tenor_months = $loan->tenor_months;
        $this->payment_method = $loan->payment_method;
        $this->purpose = $loan->purpose ?? '';
        $this->applied_at = $loan->applied_at->toDateString();
    }

    /** Pratinjau total & angsuran per bulan dari isian saat ini (0 bila belum lengkap). */
    public function preview(): array
    {
        $p = is_numeric($this->principal) ? max(0, (int) $this->principal) : 0;
        $i = $this->interest_mode === 'persen'
            ? (is_numeric($this->interest_rate) ? (int) round($p * max(0, (float) $this->interest_rate) / 100) : 0)
            : (is_numeric($this->interest) ? max(0, (int) $this->interest) : 0);
        $t = is_numeric($this->tenor_months) ? (int) $this->tenor_months : 0;

        return ['interest' => $i, 'total' => $p + $i, 'monthly' => $t > 0 ? (int) ceil(($p + $i) / $t) : 0,
            'rate' => $p > 0 ? round($i * 100 / $p, 2) : 0.0, 'rate_monthly' => $p > 0 && $t > 0 ? round($i * 100 / $p / $t, 2) : 0.0];
    }
}
