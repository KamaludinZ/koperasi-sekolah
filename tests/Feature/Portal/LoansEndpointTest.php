<?php

namespace Tests\Feature\Portal;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoansEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $sari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $this->sari = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $other = Member::factory()->teacher()->create(['name' => 'Pak Lain']);

        $run = Loan::factory()->create(['code' => 'PJM-RUN', 'member_id' => $this->sari->id, 'status' => 'disetujui', 'principal' => 900000, 'interest' => 90000,
            'tenor_months' => 3, 'payment_method' => 'transfer', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($run, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        Loan::factory()->create(['code' => 'PJM-NEW', 'member_id' => $this->sari->id, 'applied_at' => '2026-06-30']);
        Loan::factory()->create(['code' => 'PJM-OTHER', 'member_id' => $other->id]);

        $this->travelTo('2026-08-10 10:00:00');
        $svc = app(LoanPaymentService::class);
        $wrong = $svc->record($run, ['amount' => 100000, 'paid_at' => '2026-08-01', 'payment_method' => 'transfer', 'reference' => 'TRX-SALAH', 'note' => 'CATATAN-INTERNAL'], auth()->id());
        $svc->correct($wrong, 'Nominal salah ketik', ['amount' => 330000, 'paid_at' => '2026-08-01', 'payment_method' => 'transfer', 'reference' => 'TRX-OK'], auth()->id());
        auth()->logout();
        $this->actingAsMember($this->sari);
    }

    public function test_returns_only_own_loans_with_summary_and_counts(): void
    {
        $res = $this->getJson(route('portal.pinjaman.data'))->assertOk()
            ->assertJsonPath('filters.tampil', 'semua')
            ->assertJsonPath('counts', ['semua' => 2, 'berjalan' => 1, 'pengajuan' => 1, 'selesai' => 0])
            ->assertJsonPath('summary.active', 1)->assertJsonPath('summary.outstanding', 660000)
            ->assertJsonPath('summary.next.code', 'PJM-RUN')->assertJsonPath('summary.next.sequence', 2)->assertJsonPath('summary.next.due_date', '2026-09-01');
        $this->assertSame(['PJM-RUN', 'PJM-NEW'], array_column($res->json('data'), 'code'));

        $run = $res->json('data.0');
        $this->assertSame(['status' => 'berjalan', 'outstanding' => 660000, 'progress' => 33, 'overdue' => false],
            ['status' => $run['status'], 'outstanding' => $run['outstanding'], 'progress' => $run['progress'], 'overdue' => $run['overdue']]);
        $this->assertSame(600000, $run['balance']['principal_remaining']);
        $this->assertSame([['number' => 1, 'paid_at' => '2026-08-01', 'amount' => 330000, 'payment_label' => 'Transfer', 'reference_label' => 'TRX-OK', 'sequences' => [1], 'balance_after' => 660000]], $run['payments']);
        $this->assertArrayNotHasKey('member', $run);

        $json = $res->getContent();
        foreach (['PJM-OTHER', 'Pak Lain', 'TRX-SALAH', 'CATATAN-INTERNAL', 'Nominal salah ketik', 'Pengurus'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, $leak);
        }
    }

    public function test_tab_filter_and_validation(): void
    {
        $this->assertSame(['PJM-NEW'], array_column($this->getJson(route('portal.pinjaman.data', ['tampil' => 'pengajuan']))->assertOk()->json('data'), 'code'));
        $this->assertSame([], $this->getJson(route('portal.pinjaman.data', ['tampil' => 'selesai']))->json('data'));
        $this->getJson(route('portal.pinjaman.data', ['tampil' => 'x']))->assertUnprocessable()->assertJsonValidationErrors(['tampil']);
    }

    public function test_overdue_flag_without_status_refresh(): void
    {
        $this->travelTo('2026-09-05 10:00:00');
        $this->getJson(route('portal.pinjaman.data'))->assertJsonPath('data.0.overdue', true)->assertJsonPath('data.0.days_late', 4)
            ->assertJsonPath('summary.overdue', 1)->assertJsonPath('summary.late_amount', 330000);
    }

    public function test_outstanding_and_next_installment(): void
    {
        $two = Loan::factory()->create(['code' => 'PJM-TWO', 'member_id' => $this->sari->id, 'status' => 'disetujui', 'principal' => 400000, 'interest' => 0,
            'tenor_months' => 2, 'payment_method' => 'tunai', 'applied_at' => '2026-08-01']);
        app(LoanDisbursementService::class)->disburse($two, ['disbursed_on' => '2026-08-05', 'amount' => 400000]);

        $this->travelTo('2026-09-03 10:00:00'); // PJM-RUN ke-2 (1 Sep) lewat 2 hari; PJM-TWO ke-1 jatuh tempo 5 Sep
        $res = $this->getJson(route('portal.pinjaman.sisa'))->assertOk()
            ->assertJsonPath('data.totals.active', 2)->assertJsonPath('data.totals.outstanding', 1060000)
            ->assertJsonPath('data.totals.principal_remaining', 1000000)->assertJsonPath('data.totals.interest_remaining', 60000)
            ->assertJsonPath('data.totals.late_amount', 330000)->assertJsonPath('data.totals.overdue_loans', 1)
            ->assertJsonPath('data.totals.due_this_month', 530000)
            ->assertJsonPath('data.totals.next.code', 'PJM-RUN')->assertJsonPath('data.totals.next.due_date', '2026-09-01');

        $run = $res->json('data.loans.0');
        $this->assertSame('PJM-RUN', $run['code']);
        $this->assertSame([[2, '2026-09-01', 330000, true, 2], [3, '2026-10-01', 330000, false, 0]],
            array_map(fn ($i) => [$i['sequence'], $i['due_date'], $i['remaining'], $i['late'], $i['days_late']], $run['schedule']));
        $this->assertSame(['1 dari 3', 33], [$run['installments_paid'].' dari '.$run['installments'], $run['progress']]);
        $this->assertSame(200000, $res->json('data.loans.1.next.remaining'));
        $this->assertStringNotContainsString('PJM-OTHER', $res->getContent());
        $this->assertStringNotContainsString('PJM-NEW', $res->getContent()); // pengajuan belum punya sisa
    }

    public function test_outstanding_empty_for_member_without_active_loans(): void
    {
        $this->actingAsMember(Member::factory()->create());
        $this->getJson(route('portal.pinjaman.sisa'))->assertOk()->assertJsonPath('data.loans', [])
            ->assertJsonPath('data.totals.outstanding', 0)->assertJsonPath('data.totals.next', null);
        auth('member')->logout();
        $this->getJson(route('portal.pinjaman.sisa'))->assertStatus(401);
    }

    public function test_no_id_parameter_and_requires_member_session(): void
    {
        $this->getJson('/portal/pinjaman/data?member_id='.Member::where('name', 'Pak Lain')->value('id'))->assertOk()
            ->assertJsonMissing(['code' => 'PJM-OTHER']);
        $this->getJson('/portal/pinjaman/1/data')->assertNotFound();

        auth('member')->logout();
        $this->getJson(route('portal.pinjaman.data'))->assertStatus(401);
        $this->loginAs('admin'); // sesi petugas bukan sesi anggota
        $this->getJson(route('portal.pinjaman.data'))->assertStatus(401);
    }
}
