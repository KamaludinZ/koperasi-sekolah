<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Show;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BalanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $m = Member::factory()->teacher()->create();
        $this->loan = Loan::factory()->create(['status' => 'disetujui', 'principal' => 1000000, 'interest' => 50000, 'tenor_months' => 4, 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 1000000]); // 4 × 262.500 (bunga 12.500)
        $this->travelTo('2026-08-05 10:00:00');
    }

    public function test_balance_before_and_after_payments(): void
    {
        $svc = app(LoanPaymentService::class);
        $b = $svc->balance($this->loan->fresh());
        $this->assertSame([1050000, 0, 1050000, 0, 0, 0, null], [$b['total'], $b['paid'], $b['remaining'], $b['interest_paid'], $b['principal_paid'], $b['payments_count'], $b['last_payment']]);

        $svc->record($this->loan, ['amount' => 262500, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $svc->record($this->loan, ['amount' => 131250, 'paid_at' => '2026-08-05', 'payment_method' => 'transfer', 'reference' => 'TRF 9']); // setengah ke-2
        $b = $svc->balance($this->loan->fresh());
        $this->assertSame(393750, $b['paid']);
        $this->assertSame(656250, $b['remaining']);
        $this->assertSame(12500 + 6250, $b['interest_paid']);
        $this->assertSame(393750 - 18750, $b['principal_paid']);
        $this->assertSame(1000000 - 375000, $b['principal_remaining']);
        $this->assertSame(50000 - 18750, $b['interest_remaining']);
        $this->assertSame([2, 1, 4, 37], [$b['payments_count'], $b['installments_paid'], $b['installments'], $b['progress']]);
        $this->assertSame(['paid_at' => '2026-08-05', 'amount' => 131250, 'payment_label' => 'Transfer'], $b['last_payment']);
        $this->assertSame((int) $this->loan->fresh()->outstanding, $b['remaining']); // konsisten dengan sisa pinjaman

        Livewire::test(Show::class, ['loan' => $this->loan])->assertSeeHtml('data-balance')
            ->assertSeeInOrder(['Pokok', 'Rp1.000.000', 'Rp375.000', 'Rp625.000'])
            ->assertSeeInOrder(['Bunga', 'Rp50.000', 'Rp18.750', 'Rp31.250'])
            ->assertSee('2 pembayaran · 1/4 angsuran lunas')->assertSee('terakhir 5 Agt 2026 (Rp131.250, Transfer)');
    }

    public function test_full_payment_splits_exactly(): void
    {
        app(LoanPaymentService::class)->record($this->loan, ['amount' => 1050000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai']);
        $b = app(LoanPaymentService::class)->balance($this->loan->fresh());
        $this->assertSame([1000000, 50000, 0, 0, 0, 100], [$b['principal_paid'], $b['interest_paid'], $b['principal_remaining'], $b['interest_remaining'], $b['remaining'], $b['progress']]);
    }

    public function test_hidden_before_disbursement(): void
    {
        Livewire::test(Show::class, ['loan' => Loan::factory()->create()])->assertDontSeeHtml('data-balance');
    }
}
