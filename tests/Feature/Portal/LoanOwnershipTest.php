<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Loans;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use App\Services\MemberLoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/** Data pinjaman di portal selalu disaring berdasarkan anggota yang sedang masuk — di setiap rute & komponen. */
class LoanOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Member $ana;

    private Member $budi;

    private Loan $anaLoan;

    private Loan $budiLoan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $this->ana = Member::factory()->teacher()->create(['name' => 'Bu Ana']);
        $this->budi = Member::factory()->teacher()->create(['name' => 'Pak Budi']);
        $this->anaLoan = $this->running('PJM-ANA', $this->ana, 600000);
        $this->budiLoan = $this->running('PJM-BUDI', $this->budi, 770000);
        $this->travelTo('2026-08-05 10:00:00');
        app(LoanPaymentService::class)->record($this->budiLoan, ['amount' => 77000, 'paid_at' => '2026-08-05', 'payment_method' => 'transfer', 'reference' => 'TRX-BUDI-RAHASIA'], auth()->id());
        auth()->logout();
    }

    private function running(string $code, Member $m, int $principal): Loan
    {
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => $principal, 'interest' => 0,
            'tenor_months' => 2, 'payment_method' => 'transfer', 'applied_at' => '2026-06-20']);

        return app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-07-01', 'amount' => $principal]);
    }

    /** @return list<string> semua rute GET portal pinjaman, parameter {loan} diisi id pinjaman Budi */
    private function portalLoanUrls(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'portal/pinjaman') && in_array('GET', $r->methods(), true))
            ->map(fn ($r) => '/'.str_replace('{loan}', (string) $this->budiLoan->id, $r->uri()))->values()->all();
    }

    public function test_every_portal_loan_route_hides_other_members_data(): void
    {
        $urls = $this->portalLoanUrls();
        $this->assertGreaterThanOrEqual(4, count($urls)); // halaman, data, sisa, riwayat
        $this->actingAsMember($this->ana);

        foreach ($urls as $url) {
            $res = $url === '/portal/pinjaman' ? $this->get($url) : $this->getJson($url);
            $this->assertContains($res->status(), [200, 404], $url);
            foreach (['PJM-BUDI', 'Pak Budi', 'TRX-BUDI-RAHASIA', 'Rp770.000', '770000'] as $leak) {
                $this->assertStringNotContainsString($leak, $res->getContent(), "{$url} membocorkan {$leak}");
            }
        }
        $this->getJson("/portal/pinjaman/{$this->budiLoan->id}/riwayat")->assertNotFound();
        $this->getJson("/portal/pinjaman/{$this->anaLoan->id}/riwayat")->assertOk()->assertJsonPath('data.loan.code', 'PJM-ANA');
    }

    public function test_query_parameters_cannot_widen_the_scope(): void
    {
        $this->actingAsMember($this->ana);
        foreach (['member_id', 'anggota', 'member', 'id', 'loan_id'] as $param) {
            $q = "?{$param}={$this->budi->id}";
            $this->assertSame(['PJM-ANA'], array_column($this->getJson('/portal/pinjaman/data'.$q)->assertOk()->json('data'), 'code'), $param);
            $this->assertSame(['PJM-ANA'], array_column($this->getJson('/portal/pinjaman/sisa'.$q)->json('data.loans'), 'code'), $param);
        }
    }

    public function test_livewire_component_is_scoped_and_has_no_tamperable_owner(): void
    {
        $this->actingAsMember($this->ana);
        $c = Livewire::test(Loans::class)->assertSeeHtml('data-my-loan="PJM-ANA"')->assertDontSee('PJM-BUDI');
        foreach (['member', 'memberId', 'member_id', 'loanId'] as $prop) {
            $this->assertFalse(property_exists(Loans::class, $prop), "properti publik {$prop} tidak boleh ada");
        }
        $c->call('setTab', 'semua')->assertDontSee('PJM-BUDI');

        // sesi berganti anggota → data ikut berganti (tidak ada cache lintas anggota)
        $this->actingAsMember($this->budi);
        Livewire::test(Loans::class)->assertSeeHtml('data-my-loan="PJM-BUDI"')->assertDontSee('PJM-ANA');
    }

    public function test_service_scope_and_owned_loan_helper(): void
    {
        $this->assertSame(['PJM-ANA'], Loan::query()->ownedBy($this->ana)->pluck('code')->all());
        $this->assertSame(['PJM-BUDI'], Loan::query()->ownedBy($this->budi->id)->pluck('code')->all());
        $this->assertSame(0, Loan::query()->ownedBy(null)->count());
        $this->assertSame($this->anaLoan->id, MemberLoanService::ownedLoan($this->ana, $this->anaLoan->id)->id);
        foreach ([$this->budiLoan->id, 999999, '1 OR 1=1', '-1', ''] as $bad) {
            try {
                MemberLoanService::ownedLoan($this->ana, $bad);
                $this->fail('Harus 404: '.var_export($bad, true));
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }
        // 5 Agt: angsuran ke-1 (1 Agt) Ana belum dibayar → 1 menunggak; anggota tanpa pinjaman → 0
        $this->assertSame(1, Loans::overdueCount($this->ana->id));
        $this->assertSame(0, Loans::overdueCount(Member::factory()->create()->id));
    }

    public function test_revoked_portal_access_is_logged_out_immediately(): void
    {
        $this->actingAsMember($this->ana);
        $this->getJson('/portal/pinjaman/data')->assertOk();
        $this->ana->update(['is_active' => false]);
        $this->getJson('/portal/pinjaman/data')->assertStatus(401);
        $this->getJson("/portal/pinjaman/{$this->anaLoan->id}/riwayat")->assertStatus(401);
        $this->get('/portal/pinjaman')->assertRedirect(route('portal.login'));
    }
}
