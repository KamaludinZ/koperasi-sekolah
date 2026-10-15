<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanOverdueDetector;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanApiOverdueTest extends TestCase
{
    use RefreshDatabase;

    private Loan $late;

    private Loan $ok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        foreach (['late' => ['PJM-L', 'Bu Lambat'], 'ok' => ['PJM-OK', 'Pak Rajin']] as $prop => [$code, $name]) {
            $m = Member::factory()->teacher()->create(['name' => $name]);
            $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 300000, 'interest' => 0,
                'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);
            $this->{$prop} = app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-07-01', 'amount' => 300000]);
        }
        $this->travelTo('2026-08-01 10:00:00');
        app(LoanPaymentService::class)->record($this->ok, ['amount' => 100000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        $this->travelTo('2026-08-11 10:00:00'); // PJM-L ke-1 lewat 10 hari, status DB masih berjalan
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    public function test_list_includes_effective_overdue_before_status_refresh(): void
    {
        $rows = collect($this->getJson(route('pinjaman.list'))->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['berjalan', 'menunggak', true], [$rows['PJM-L']['status'], $rows['PJM-L']['effective_status'], $rows['PJM-L']['is_overdue']]);
        $this->assertSame(['late_count' => 1, 'late_amount' => 100000, 'oldest_due' => '2026-08-01', 'days_late' => 10], $rows['PJM-L']['overdue']);
        $this->assertNull($rows['PJM-L']['overdue_since']);
        $this->assertSame(['berjalan', false, null], [$rows['PJM-OK']['effective_status'], $rows['PJM-OK']['is_overdue'], $rows['PJM-OK']['overdue']]);

        $this->assertSame(['PJM-L'], array_column($this->getJson(route('pinjaman.list', ['status' => 'menunggak']))->json('data'), 'code'));
        $this->assertSame(['PJM-OK'], array_column($this->getJson(route('pinjaman.list', ['status' => 'berjalan']))->json('data'), 'code'));
        $this->getJson(route('pinjaman.list'))->assertJsonPath('meta.stats.counts.menunggak', 1)->assertJsonPath('meta.stats.counts.berjalan', 1);
    }

    public function test_detail_installments_and_summary_after_detection(): void
    {
        $this->getJson(route('pinjaman.data', $this->late))->assertOk()
            ->assertJsonPath('data.loan.effective_status', 'menunggak')->assertJsonPath('data.loan.overdue.days_late', 10)
            ->assertJsonPath('data.loan.overdue_since', null);

        app(LoanOverdueDetector::class)->run();
        $this->getJson(route('pinjaman.data', $this->late))->assertJsonPath('data.loan.status', 'menunggak')
            ->assertJsonPath('data.loan.overdue_since', '2026-08-01')->assertJsonPath('data.loan.is_overdue', true);
        $this->getJson(route('pinjaman.installments', $this->late))->assertOk()
            ->assertJsonPath('meta.loan.effective_status', 'menunggak')->assertJsonPath('meta.loan.overdue.late_amount', 100000);
        $this->getJson(route('pinjaman.data', $this->ok))->assertJsonPath('data.loan.is_overdue', false)->assertJsonPath('data.loan.overdue', null);
        $this->getJson(route('pinjaman.summary'))->assertOk()->assertJsonPath('data.overdue_loans', 1)
            ->assertJsonPath('data.late_installments.count', 1)->assertJsonPath('data.late_installments.amount', 100000);
    }

    public function test_paid_off_and_pending_loans_never_flagged(): void
    {
        app(LoanPaymentService::class)->record($this->late, ['amount' => 300000, 'paid_at' => '2026-08-11', 'payment_method' => 'tunai']);
        $pending = Loan::factory()->create(['code' => 'PJM-P']);
        $rows = collect($this->getJson(route('pinjaman.list'))->json('data'))->keyBy('code');
        $this->assertSame(['lunas', false], [$rows['PJM-L']['effective_status'], $rows['PJM-L']['is_overdue']]);
        $this->assertSame([$pending->status, false], [$rows['PJM-P']['effective_status'], $rows['PJM-P']['is_overdue']]);
    }
}
