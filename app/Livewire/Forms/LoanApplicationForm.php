<?php

namespace App\Livewire\Forms;

use App\Services\LoanApplicationService;
use Livewire\Form;

/** Form pengajuan pinjaman (anggota, nominal, bunga, tenor, metode, keperluan). Aturan dari LoanApplicationService. */
class LoanApplicationForm extends Form
{
    public ?int $member_id = null;

    public int|string|null $principal = '';

    public int|string|null $interest = '';

    public int|string|null $tenor_months = '';

    public string $payment_method = 'potong_gaji';

    public ?string $purpose = '';

    public string $applied_at = '';

    protected function rules(): array
    {
        return LoanApplicationService::rules();
    }

    protected function messages(): array
    {
        return LoanApplicationService::messages();
    }

    public function blank(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->applied_at = today()->toDateString();
    }

    /** Pratinjau total & angsuran per bulan dari isian saat ini (0 bila belum lengkap). */
    public function preview(): array
    {
        $p = is_numeric($this->principal) ? max(0, (int) $this->principal) : 0;
        $i = is_numeric($this->interest) ? max(0, (int) $this->interest) : 0;
        $t = is_numeric($this->tenor_months) ? (int) $this->tenor_months : 0;

        return ['total' => $p + $i, 'monthly' => $t > 0 ? (int) ceil(($p + $i) / $t) : 0];
    }
}
