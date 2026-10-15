<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanInstallmentQuery;
use App\Services\LoanPaymentService;
use App\Services\LoanService;
use App\Services\MemberLoanService;
use App\Support\LoanOverdue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueRuleTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-31 09:00:00');
        $this->loginAs('pengurus');
        $this->member = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-T', 'member_id' => $this->member->id, 'status' => 'disetujui', 'principal' => 600000,
            'interest' => 0, 'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-07-20']);
        // cair 31 Jul → jatuh tempo 31 Agt, 30 Sep (akhir bulan tidak meloncat), 31 Okt
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-31', 'amount' => 600000]);
    }

    private function first(): LoanInstallment
    {
        return $this->loan->installments()->where('sequence', 1)->first();
    }

    public function test_due_today_is_not_late_but_tomorrow_is(): void
    {
        $this->travelTo('2026-08-31 23:59:00');
        $this->assertFalse(LoanOverdue::isLate($this->first()));
        $this->assertSame('belum', LoanOverdue::status($this->first()));

        $this->travelTo('2026-09-01 00:00:01');
        $this->assertTrue(LoanOverdue::isLate($this->first()));
        $this->assertSame(1, LoanOverdue::daysLate($this->first()));
        $this->assertSame('menunggak', LoanOverdue::status($this->first()));
    }

    public function test_partial_payment_is_still_late_and_full_payment_clears(): void
    {
        $this->travelTo('2026-09-10 10:00:00');
        $svc = app(LoanPaymentService::class);
        $svc->record($this->loan, ['amount' => 150000, 'paid_at' => '2026-09-10', 'payment_method' => 'tunai'], auth()->id());
        $this->assertTrue(LoanOverdue::isLate($this->first()));
        $this->assertSame(10, LoanOverdue::daysLate($this->first()));
        $this->assertSame('menunggak', $this->loan->fresh()->status);

        $svc->record($this->loan->fresh(), ['amount' => 50000, 'paid_at' => '2026-09-10', 'payment_method' => 'tunai'], auth()->id());
        $this->assertFalse(LoanOverdue::isLate($this->first()));
        $this->assertSame('berjalan', $this->loan->fresh()->status); // ke-2 (30 Sep) belum jatuh tempo
    }

    public function test_grace_days_shift_the_cutoff_but_days_late_counts_from_due_date(): void
    {
        config(['koperasi.loan_grace_days' => 3]);
        $this->travelTo('2026-09-03 10:00:00'); // 3 hari lewat → masih dalam toleransi
        $this->assertFalse(LoanOverdue::isLate($this->first()));
        $this->travelTo('2026-09-04 10:00:00');
        $this->assertTrue(LoanOverdue::isLate($this->first()));
        $this->assertSame(4, LoanOverdue::daysLate($this->first()));

        config(['koperasi.loan_grace_days' => 999]);
        $this->assertSame(LoanOverdue::MAX_GRACE_DAYS, LoanOverdue::graceDays());
        config(['koperasi.loan_grace_days' => -5]);
        $this->assertSame(0, LoanOverdue::graceDays());
    }

    public function test_staff_console_installment_list_portal_and_recalc_agree(): void
    {
        foreach (['2026-08-31 10:00:00' => [false, 0, 'berjalan'], '2026-09-02 10:00:00' => [true, 2, 'menunggak']] as $at => [$late, $days, $status]) {
            $this->travelTo($at);
            $staff = collect(app(LoanService::class)->detail($this->loan->fresh())['installments'])->firstWhere('sequence', 1);
            $list = LoanInstallmentQuery::toArray($this->first()->load('loan.member'));
            $portal = app(MemberLoanService::class)->outstanding($this->member)['loans'][0]['schedule'][0];
            $lateQuery = LoanOverdue::scope(LoanInstallment::query())->count();

            $this->assertSame([$late, $days], [$staff['late'], $staff['days_late']], "konsol {$at}");
            $this->assertSame([$late, $days], [$list['late'], $list['days_late']], "daftar {$at}");
            $this->assertSame([$late, $days], [$portal['late'], $portal['days_late']], "portal {$at}");
            $this->assertSame($late ? 1 : 0, $lateQuery, "query {$at}");
            $this->assertSame($late ? 1 : 0, MemberLoanService::overdueCount($this->member->id), "navigasi {$at}");
            $this->assertSame($status, app(LoanPaymentService::class)->recalculate($this->loan)['after']['status'], "hitung ulang {$at}");
        }
    }

    public function test_month_end_due_dates(): void
    {
        $this->assertSame(['2026-08-31', '2026-09-30', '2026-10-31'],
            $this->loan->installments()->orderBy('sequence')->get()->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->travelTo('2026-10-01 08:00:00');
        $this->assertSame([31, 1], $this->loan->installments()->orderBy('sequence')->get()->filter(fn ($i) => LoanOverdue::isLate($i))
            ->map(fn ($i) => LoanOverdue::daysLate($i))->values()->all());
    }
}
