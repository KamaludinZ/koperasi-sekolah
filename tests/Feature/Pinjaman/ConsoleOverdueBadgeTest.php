<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Index;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConsoleOverdueBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        foreach (['PJM-TELAT' => 'Bu Telat', 'PJM-LANCAR' => 'Pak Lancar'] as $code => $name) {
            $m = Member::factory()->teacher()->create(['name' => $name]);
            $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 300000, 'interest' => 0,
                'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);
            app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-07-01', 'amount' => 300000]);
        }
        $this->travelTo('2026-08-01 10:00:00');
        app(LoanPaymentService::class)->record(Loan::where('code', 'PJM-LANCAR')->first(), ['amount' => 100000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        // 13 Agt: PJM-TELAT ke-1 (1 Agt) belum dibayar → menunggak 12 hari, walau kolom status masih "berjalan"
        $this->travelTo('2026-08-13 10:00:00');
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    public function test_console_shows_effective_overdue_badge_before_status_refresh(): void
    {
        $this->assertSame('berjalan', Loan::where('code', 'PJM-TELAT')->value('status'));

        $this->get(route('pinjaman'))->assertOk()
            ->assertSeeHtml('data-overdue-badge="PJM-TELAT"')->assertSee('12 hari · Rp100.000')
            ->assertDontSeeHtml('data-overdue-badge="PJM-LANCAR"')
            ->assertSeeHtml('data-nav-overdue>1<')
            ->assertSee(route('pinjaman.tunggakan', ['q' => 'PJM-TELAT']), false);

        $stats = app(LoanService::class)->stats();
        $this->assertSame([1, 1], [$stats['counts']['berjalan'], $stats['counts']['menunggak']]);
        $this->assertSame(300000, $stats['overdue_outstanding']);
    }

    public function test_status_filter_uses_effective_state(): void
    {
        Livewire::test(Index::class)->call('filterStatus', 'menunggak')
            ->assertSeeHtml('data-loan-row="PJM-TELAT"')->assertDontSeeHtml('data-loan-row="PJM-LANCAR"')
            ->call('filterStatus', 'berjalan')
            ->assertSeeHtml('data-loan-row="PJM-LANCAR"')->assertDontSeeHtml('data-loan-row="PJM-TELAT"');
    }

    public function test_badge_disappears_after_payment_and_nav_badge_hidden_when_none(): void
    {
        app(LoanPaymentService::class)->record(Loan::where('code', 'PJM-TELAT')->first(), ['amount' => 100000, 'paid_at' => '2026-08-13', 'payment_method' => 'tunai']);
        $this->get(route('pinjaman'))->assertOk()->assertDontSeeHtml('data-overdue-badge=')->assertDontSeeHtml('data-nav-overdue');
        $this->assertSame([], LoanService::overdueFlags(Loan::pluck('id')->all()));
        $this->assertSame([], LoanService::overdueFlags([]));
    }
}
