<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Show;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentValidationTest extends TestCase
{
    use RefreshDatabase;

    private Loan $guru;

    private Loan $siswa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $this->guru = $this->running('PJM-G', Member::factory()->teacher()->create(['name' => 'Bu Guru']), 'potong_gaji');
        $this->siswa = $this->running('PJM-S', Member::factory()->create(['name' => 'Ani Siswa', 'type' => 'siswa']), 'tunai');
        $this->travelTo('2026-09-10 10:00:00');
    }

    private function running(string $code, Member $m, string $method): Loan
    {
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 600000,
            'interest' => 0, 'tenor_months' => 6, 'payment_method' => $method, 'applied_at' => '2026-06-20']);

        return app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-07-01', 'amount' => 600000]);
    }

    private function pay(Loan $loan, array $body)
    {
        return $this->postJson(route('pinjaman.installments.store', $loan), $body);
    }

    public function test_minimum_amount_except_paying_off(): void
    {
        $this->pay($this->guru, ['amount' => 999, 'payment_method' => 'tunai'])->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'Nominal minimal Rp1.000, kecuali untuk melunasi sisa Rp600.000.');
        $this->pay($this->guru, ['amount' => 599500, 'payment_method' => 'tunai'])->assertCreated();
        $this->pay($this->guru, ['amount' => 500, 'payment_method' => 'tunai'])->assertCreated()->assertJsonPath('data.loan.status', 'lunas');
    }

    public function test_salary_deduction_only_for_teachers(): void
    {
        $this->pay($this->siswa, ['amount' => 100000, 'payment_method' => 'potong_gaji', 'reference' => '2026-09'])->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);
        $this->pay($this->guru, ['amount' => 100000, 'payment_method' => 'potong_gaji', 'reference' => '2026-09'])->assertCreated()
            ->assertJsonPath('data.payment.reference_label', 'Gaji September 2026');
    }

    public function test_one_salary_deduction_per_month_and_not_before_disbursement(): void
    {
        $this->pay($this->guru, ['amount' => 100000, 'payment_method' => 'potong_gaji', 'reference' => '2026-06'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Bulan gaji tidak boleh sebelum bulan pencairan (Gaji Juli 2026).');
        $first = $this->pay($this->guru, ['amount' => 100000, 'payment_method' => 'potong_gaji', 'reference' => '2026-08'])->assertCreated();
        $this->pay($this->guru, ['amount' => 100000, 'payment_method' => 'potong_gaji', 'reference' => '2026-08'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'Gaji Agustus 2026 sudah dipotong untuk PJM-G.');

        // setelah potongan itu dikoreksi (dibatalkan), bulan yang sama boleh dicatat lagi
        app(LoanPaymentService::class)->correct(LoanPayment::find($first->json('data.payment.id')), 'Potongan gagal diproses', null, auth()->id());
        $this->pay($this->guru->fresh(), ['amount' => 100000, 'payment_method' => 'potong_gaji', 'reference' => '2026-08'])->assertCreated();
    }

    public function test_transfer_reference_cannot_be_reused_across_loans(): void
    {
        $this->pay($this->guru, ['amount' => 100000, 'payment_method' => 'transfer', 'reference' => '  TRX   889 '])->assertCreated()
            ->assertJsonPath('data.payment.reference', 'TRX 889');
        $this->pay($this->guru, ['amount' => 100000, 'payment_method' => 'transfer', 'reference' => 'trx889'])->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', 'No./berita transfer ini sudah dipakai untuk pembayaran PJM-G.');
        $this->pay($this->siswa, ['amount' => 100000, 'payment_method' => 'transfer', 'reference' => 'TRX 889'])->assertUnprocessable();
        $this->pay($this->siswa, ['amount' => 100000, 'payment_method' => 'transfer', 'reference' => 'TRX 890'])->assertCreated();
        $this->pay($this->siswa, ['amount' => 100000, 'payment_method' => 'transfer', 'reference' => "TRX\x07891"])->assertUnprocessable()->assertJsonValidationErrors(['reference']);
        $this->pay($this->siswa, ['amount' => 100000, 'payment_method' => 'tunai', 'note' => "baris1\nbaris2"])->assertCreated();
        $this->pay($this->siswa, ['amount' => 100000, 'payment_method' => 'tunai', 'note' => "x\x00y"])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    }

    public function test_correction_replacement_follows_same_rules_but_may_keep_own_reference(): void
    {
        $svc = app(LoanPaymentService::class);
        $p = $svc->record($this->guru, ['amount' => 100000, 'paid_at' => '2026-09-01', 'payment_method' => 'transfer', 'reference' => 'TRX-1'], auth()->id());
        $svc->record($this->siswa, ['amount' => 100000, 'paid_at' => '2026-09-01', 'payment_method' => 'transfer', 'reference' => 'TRX-2'], auth()->id());

        // nominal salah, rujukan sama → boleh (pembayaran yang dikoreksi diabaikan)
        $new = $svc->correct($p, 'Nominal salah ketik', ['amount' => 120000, 'paid_at' => '2026-09-01', 'payment_method' => 'transfer', 'reference' => 'TRX-1'], auth()->id());
        $this->assertSame(120000, (int) $new->amount);

        foreach ([
            [['amount' => 120000, 'paid_at' => '2026-09-01', 'payment_method' => 'transfer', 'reference' => 'trx-2'], 'reference'],
            [['amount' => 50, 'paid_at' => '2026-09-01', 'payment_method' => 'tunai'], 'amount'],
        ] as [$replacement, $field]) {
            try {
                $svc->correct($new, 'Koreksi kedua', $replacement, auth()->id());
                $this->fail('Pengganti seharusnya ditolak.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertNull($new->fresh()->voided_at); // penolakan tidak membatalkan pembayaran
    }

    public function test_detail_page_hides_salary_deduction_for_students_and_shows_errors(): void
    {
        Livewire::test(Show::class, ['loan' => $this->siswa])->call('openPayment')
            ->assertDontSee('Potong gaji')
            ->set('payMethod', 'potong_gaji')->set('payAmount', 100000)->set('payRef', '2026-09')->call('recordPayment')
            ->assertHasErrors(['payMethod']);
        Livewire::test(Show::class, ['loan' => $this->guru])->call('openPayment')->assertSee('Potong gaji');
        $this->assertSame(0, LoanPayment::count());
    }
}
