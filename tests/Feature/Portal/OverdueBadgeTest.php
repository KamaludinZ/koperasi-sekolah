<?php

namespace Tests\Feature\Portal;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tanda menunggak di portal anggota: beranda, menu, dan halaman Pinjaman saya — dari jatuh tempo, bukan hanya kolom status. */
class OverdueBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Member $sari;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $this->sari = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-S', 'member_id' => $this->sari->id, 'status' => 'disetujui', 'principal' => 300000,
            'interest' => 0, 'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 300000]);
        auth()->logout();
        $this->actingAsMember($this->sari);
    }

    public function test_home_shows_running_loan_card_when_current(): void
    {
        $this->travelTo('2026-07-20 10:00:00');
        $this->get(route('portal'))->assertOk()
            ->assertSeeHtml('data-home-loan="berjalan"')->assertSeeText('Pinjaman · sisa Rp300.000')
            ->assertSee('Angsuran berikutnya Rp100.000 · 1 Agt 2026')
            ->assertDontSeeHtml('data-home-loan="menunggak"')->assertDontSeeHtml('data-nav-alert');
    }

    public function test_home_nav_and_loans_page_flag_overdue_before_status_refresh(): void
    {
        $this->travelTo('2026-08-06 10:00:00');
        $this->assertSame('berjalan', $this->loan->fresh()->status);

        $this->get(route('portal'))->assertOk()
            ->assertSeeHtml('data-home-loan="menunggak"')->assertSee('Pinjaman menunggak')
            ->assertSeeText('Tunggakan Rp100.000 · terlambat 5 hari')
            ->assertSeeHtml('data-nav-alert="portal.pinjaman"')
            ->assertSee(route('portal.pinjaman'), false);

        $this->get(route('portal.pinjaman'))->assertOk()
            ->assertSeeHtml('data-overdue-alert')->assertSeeHtml('data-overdue-ribbon')->assertSee('Menunggak 5 hari · Rp100.000')
            ->assertSeeHtml('data-loan-status="menunggak"');
    }

    public function test_badges_clear_after_payment(): void
    {
        $this->travelTo('2026-08-06 10:00:00');
        app(LoanPaymentService::class)->record($this->loan, ['amount' => 100000, 'paid_at' => '2026-08-06', 'payment_method' => 'tunai']);
        $this->get(route('portal'))->assertOk()->assertSeeHtml('data-home-loan="berjalan"')->assertDontSeeHtml('data-home-loan="menunggak"')->assertDontSeeHtml('data-nav-alert');
        $this->get(route('portal.pinjaman'))->assertDontSeeHtml('data-overdue-alert')->assertSeeHtml('data-loan-status="berjalan"');
    }

    public function test_member_without_loans_sees_no_loan_card_and_other_members_overdue_does_not_leak(): void
    {
        $this->travelTo('2026-08-06 10:00:00');
        $this->actingAsMember(Member::factory()->create(['name' => 'Ani']));
        $this->get(route('portal'))->assertOk()->assertDontSeeHtml('data-home-loan')->assertDontSeeHtml('data-nav-alert')->assertDontSee('PJM-S');
    }
}
