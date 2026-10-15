<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallmentListEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $a = Member::factory()->teacher()->create(['name' => 'Bu Wati', 'member_code' => 'G-001']);
        $b = Member::factory()->teacher()->create(['name' => 'Pak Joko', 'member_code' => 'G-002']);
        $this->loan('PJM-A', $a, 'potong_gaji', 300000, 30000, '2026-07-01');   // ke-1 1 Agt, ke-2 1 Sep, ke-3 1 Okt @110.000
        $this->loan('PJM-B', $b, 'transfer', 200000, 0, '2026-07-15');          // ke-1 15 Agt, ke-2 15 Sep @100.000
        $this->travelTo('2026-09-10 10:00:00');
        app(LoanPaymentService::class)->record(Loan::where('code', 'PJM-B')->first(), ['amount' => 100000, 'paid_at' => '2026-08-15', 'payment_method' => 'transfer', 'reference' => 'TRX-B1'], auth()->id());
    }

    private function loan(string $code, Member $m, string $method, int $principal, int $interest, string $on): void
    {
        $tenor = $code === 'PJM-A' ? 3 : 2;
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => $principal,
            'interest' => $interest, 'tenor_months' => $tenor, 'payment_method' => $method, 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => $on, 'amount' => $principal]);
    }

    private function codes($res): array
    {
        return collect($res->json('data'))->map(fn ($r) => $r['loan']['code'].'#'.$r['sequence'])->all();
    }

    public function test_default_is_due_this_month_with_summary_and_meta(): void
    {
        $res = $this->getJson(route('pinjaman.angsuran.data'))->assertOk()
            ->assertJsonPath('filters.tampil', 'bulan_ini')
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 30)
            ->assertJsonPath('summary.due_month.count', 2)->assertJsonPath('summary.due_month.amount', 210000)
            ->assertJsonPath('summary.late.count', 2)->assertJsonPath('summary.late.amount', 220000);
        $this->assertSame(['PJM-A#2', 'PJM-B#2'], $this->codes($res));

        $first = $res->json('data.0');
        $expected = ['due_date' => '2026-09-01', 'amount' => 110000, 'remaining' => 110000, 'principal_part' => 100000, 'interest_part' => 10000,
            'late' => true, 'days_late' => 9, 'status_label' => 'Belum dibayar'];
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $first[$key], $key);
        }
        $this->assertSame(['id' => $first['member']['id'], 'code' => 'G-001', 'name' => 'Bu Wati'], $first['member']);
        $this->assertSame('Potong gaji', $first['loan']['payment_label']);
    }

    public function test_filters(): void
    {
        $this->assertSame(['PJM-A#1', 'PJM-A#2'], $this->codes($this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'terlambat']))->assertOk()));
        $this->assertSame(['PJM-B#1'], $this->codes($this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'dibayar']))));
        $this->assertCount(4, $this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'belum']))->json('data'));
        $this->assertCount(5, $this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua']))->json('data'));
        $this->assertSame(['PJM-A#3'], $this->codes($this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'bulan' => '2026-10']))));
        $this->assertSame(['PJM-B#2', 'PJM-B#1'], $this->codes($this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'metode' => 'transfer']))));
        $this->assertSame(['PJM-B#2', 'PJM-B#1'], $this->codes($this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'q' => 'joko']))));
        $this->assertSame(['PJM-A#1', 'PJM-A#2', 'PJM-A#3'], $this->codes($this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'q' => 'g-001']))));
        $this->assertSame([], $this->codes($this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'q' => '%']))));
        // "bulan ini" mengabaikan bulan
        $this->getJson(route('pinjaman.angsuran.data', ['bulan' => '2026-10']))->assertJsonPath('filters.bulan', null)->assertJsonPath('meta.total', 2);
    }

    public function test_pagination(): void
    {
        $p1 = $this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'per_page' => 2]))->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 5);
        $p3 = $this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'per_page' => 2, 'page' => 3]))->assertJsonPath('meta.current_page', 3);
        $this->assertCount(2, $p1->json('data'));
        $this->assertCount(1, $p3->json('data'));
        $this->assertSame([], $this->getJson(route('pinjaman.angsuran.data', ['tampil' => 'semua', 'page' => 99]))->json('data'));
    }

    public function test_invalid_parameters_rejected(): void
    {
        foreach ([['tampil' => 'x'], ['bulan' => '2026-13'], ['bulan' => '2026-9'], ['metode' => 'kartu'], ['q' => str_repeat('a', 101)],
            ['per_page' => 101], ['per_page' => 0], ['page' => 0], ['page' => 'a'], ['tampil' => ['semua']]] as $params) {
            $this->getJson(route('pinjaman.angsuran.data', $params))->assertUnprocessable();
        }
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.angsuran.data'))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pinjaman.angsuran.data'))->assertUnauthorized();
        $this->loginAs('admin');
        $this->getJson(route('pinjaman.angsuran.data'))->assertOk();
    }
}
