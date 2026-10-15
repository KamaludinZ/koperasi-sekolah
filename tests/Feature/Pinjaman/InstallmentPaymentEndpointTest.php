<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanInstallmentChange;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallmentPaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $member = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-E', 'member_id' => $member->id, 'status' => 'disetujui', 'principal' => 900000,
            'interest' => 90000, 'tenor_months' => 3, 'payment_method' => 'transfer', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        $this->travelTo('2026-08-03 10:00:00');
    }

    private function url(?Loan $loan = null): string
    {
        return route('pinjaman.installments.store', $loan ?? $this->loan);
    }

    public function test_records_payment_with_allocation_balance_and_history(): void
    {
        $res = $this->postJson($this->url(), ['amount' => 400000, 'paid_at' => '2026-08-02', 'payment_method' => 'transfer', 'reference' => 'TRX-77', 'note' => 'via BSI'])
            ->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('data.payment.amount', 400000)
            ->assertJsonPath('data.payment.sequences', [1, 2])
            ->assertJsonPath('data.payment.reference', 'TRX-77')
            ->assertJsonPath('data.payment.user', auth()->user()->name)
            ->assertJsonPath('data.loan.status', 'berjalan')
            ->assertJsonPath('data.loan.outstanding', 590000)
            ->assertJsonPath('data.balance.paid', 400000)
            ->assertJsonPath('data.balance.installments_paid', 1)
            ->assertJsonPath('message', 'Angsuran Rp400.000 untuk angsuran ke-1, 2 tercatat. Sisa Rp590.000.');

        $id = $res->json('data.payment.id');
        $this->assertSame(2, LoanInstallmentChange::where('loan_payment_id', $id)->where('event', 'pembayaran')->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'pinjaman.angsuran']);
    }

    public function test_defaults_to_today_and_borrower_method(): void
    {
        $this->postJson($this->url(), ['amount' => 330000, 'reference' => 'TRX-1'])->assertCreated()
            ->assertJsonPath('data.payment.paid_at', '2026-08-03')->assertJsonPath('data.payment.payment_method', 'transfer');
    }

    public function test_validation_errors(): void
    {
        $this->postJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson($this->url(), ['amount' => 990001, 'reference' => 'TRX-1'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson($this->url(), ['amount' => 0, 'reference' => 'TRX-1'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson($this->url(), ['amount' => 1000.5, 'reference' => 'TRX-1'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson($this->url(), ['amount' => 1000, 'paid_at' => '2026-08-04', 'reference' => 'TRX-1'])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
        $this->postJson($this->url(), ['amount' => 1000, 'paid_at' => '2026-06-30', 'reference' => 'TRX-1'])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
        $this->postJson($this->url(), ['amount' => 1000, 'paid_at' => '03/08/2026', 'reference' => 'TRX-1'])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
        $this->postJson($this->url(), ['amount' => 1000, 'payment_method' => 'kartu'])->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);
        $this->postJson($this->url(), ['amount' => 1000, 'payment_method' => 'transfer'])->assertUnprocessable()->assertJsonValidationErrors(['reference']);
        $this->postJson($this->url(), ['amount' => 1000, 'payment_method' => 'potong_gaji', 'reference' => '2027-05'])->assertUnprocessable()->assertJsonValidationErrors(['reference']);
        $this->postJson($this->url(), ['amount' => 1000, 'payment_method' => 'tunai', 'note' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->assertSame(0, LoanPayment::count());
        $this->assertSame(0, LoanInstallmentChange::count());
    }

    public function test_idempotency_key_prevents_double_payment(): void
    {
        $body = ['amount' => 330000, 'payment_method' => 'tunai'];
        $first = $this->postJson($this->url(), $body, ['Idempotency-Key' => 'pay-abc-1'])->assertCreated();
        $this->postJson($this->url(), $body, ['Idempotency-Key' => 'pay-abc-1'])->assertOk()
            ->assertJsonPath('replayed', true)->assertJsonPath('data.payment.id', $first->json('data.payment.id'))
            ->assertJsonPath('data.loan.outstanding', 660000)->assertJsonPath('message', 'Pembayaran ini sudah tercatat sebelumnya.');
        $this->assertSame(1, LoanPayment::count());

        $this->postJson($this->url(), $body, ['Idempotency-Key' => 'bad key!'])->assertUnprocessable()->assertJsonValidationErrors(['client_ref']);
        $this->postJson($this->url(), $body, ['Idempotency-Key' => str_repeat('a', 65)])->assertUnprocessable();

        $other = Loan::factory()->running()->create(['principal' => 100000, 'interest' => 0, 'outstanding' => 100000, 'tenor_months' => 1, 'disbursed_at' => '2026-07-01']);
        $this->postJson($this->url($other), $body, ['Idempotency-Key' => 'pay-abc-1'])->assertUnprocessable()->assertJsonValidationErrors(['client_ref']);
        $this->postJson($this->url(), $body, ['Idempotency-Key' => 'pay-abc-2'])->assertCreated();
        $this->assertSame(2, LoanPayment::count());
    }

    public function test_full_payment_marks_paid_off_and_rejects_further_payments(): void
    {
        $this->postJson($this->url(), ['amount' => 990000, 'payment_method' => 'tunai'])->assertCreated()
            ->assertJsonPath('data.loan.status', 'lunas')->assertJsonPath('data.loan.outstanding', 0)
            ->assertJsonPath('message', 'Angsuran Rp990.000 untuk angsuran ke-1, 2, 3 tercatat. Pinjaman PJM-E lunas.');
        $this->postJson($this->url(), ['amount' => 1, 'payment_method' => 'tunai'])->assertUnprocessable();
        $this->assertSame(1, LoanPayment::count());
    }

    public function test_rejects_loans_that_are_not_running(): void
    {
        foreach (['diajukan', 'disetujui', 'ditolak'] as $status) {
            $loan = Loan::factory()->create(['status' => $status, 'outstanding' => 0]);
            $this->postJson($this->url($loan), ['amount' => 1000, 'payment_method' => 'tunai'])->assertUnprocessable();
        }
        $this->assertSame(0, LoanPayment::count());
        $this->postJson('/pinjaman/999999/angsuran', ['amount' => 1000, 'payment_method' => 'tunai'])->assertNotFound();
    }

    public function test_access_restricted_to_loan_managers(): void
    {
        $this->loginAs('kasir');
        $this->postJson($this->url(), ['amount' => 1000, 'payment_method' => 'tunai'])->assertForbidden();
        auth()->logout();
        $this->postJson($this->url(), ['amount' => 1000, 'payment_method' => 'tunai'])->assertUnauthorized();
        $this->loginAs('admin');
        $this->postJson($this->url(), ['amount' => 1000, 'payment_method' => 'tunai'])->assertCreated();
        $this->assertSame(1, LoanPayment::count());
    }
}
