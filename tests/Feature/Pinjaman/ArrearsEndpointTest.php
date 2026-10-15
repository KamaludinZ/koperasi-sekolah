<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanOverdueDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrearsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('pengurus');
        $this->loan('PJM-LAMA', 'Bu Wati', 'potong_gaji', '2026-05-01', 5);
        $this->loan('PJM-SEDANG', 'Pak Joko', 'transfer', '2026-07-20', 3);
        $this->loan('PJM-BARU', 'Bu Ani', 'tunai', '2026-08-25', 3);
        $this->loan('PJM-LANCAR', 'Pak Budi', 'tunai', '2026-09-15', 3);
        $this->travelTo('2026-10-10 10:00:00');
        app(LoanOverdueDetector::class)->run(); // isi overdue_since
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    private function loan(string $code, string $name, string $method, string $on, int $tenor): void
    {
        $this->travelTo($on.' 09:00:00');
        $m = Member::factory()->teacher()->create(['name' => $name, 'phone' => '081200000000']);
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 100000 * $tenor, 'interest' => 0,
            'tenor_months' => $tenor, 'payment_method' => $method, 'applied_at' => $on]);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => $on, 'amount' => 100000 * $tenor]);
    }

    public function test_lists_overdue_loans_with_summary_and_meta(): void
    {
        $res = $this->getJson(route('pinjaman.tunggakan.data'))->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('summary.loans', 3)->assertJsonPath('summary.amount', 800000)
            ->assertJsonPath('summary.buckets.90+.count', 1)->assertJsonPath('grace_days', 0)->assertJsonPath('filters.urut', 'terlama');
        $this->assertSame(['PJM-LAMA', 'PJM-SEDANG', 'PJM-BARU'], array_column($res->json('data'), 'code'));
        $first = $res->json('data.0');
        foreach (['late_count' => 5, 'late_amount' => 500000, 'oldest_due' => '2026-06-01', 'days_late' => 131, 'bucket' => '90+',
            'overdue_since' => '2026-06-01', 'payment_method' => 'potong_gaji', 'status' => 'menunggak'] as $k => $v) {
            $this->assertSame($v, $first[$k], $k);
        }
        $this->assertSame('081200000000', $first['member']['phone']);
    }

    public function test_filters_sort_pagination(): void
    {
        $codes = fn ($p) => array_column($this->getJson(route('pinjaman.tunggakan.data', $p))->assertOk()->json('data'), 'code');
        $this->assertSame(['PJM-SEDANG'], $codes(['umur' => '31-60']));
        $this->assertSame(['PJM-BARU'], $codes(['metode' => 'tunai']));
        $this->assertSame(['PJM-SEDANG'], $codes(['q' => 'joko']));
        $this->assertSame([], $codes(['q' => '%']));
        $this->assertSame(['PJM-LAMA', 'PJM-SEDANG', 'PJM-BARU'], $codes(['urut' => 'terbesar']));
        $this->assertSame(['PJM-BARU'], $codes(['per_page' => 2, 'page' => 2]));
        $this->getJson(route('pinjaman.tunggakan.data', ['umur' => '31-60']))->assertJsonPath('meta.total', 1)->assertJsonPath('summary.loans', 3);
    }

    public function test_invalid_parameters_and_access(): void
    {
        foreach ([['umur' => '0-1'], ['metode' => 'kartu'], ['urut' => 'acak'], ['q' => str_repeat('a', 101)], ['per_page' => 101], ['page' => 0], ['umur' => ['90+']]] as $p) {
            $this->getJson(route('pinjaman.tunggakan.data', $p))->assertUnprocessable();
        }
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.tunggakan.data'))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pinjaman.tunggakan.data'))->assertUnauthorized();
    }
}
