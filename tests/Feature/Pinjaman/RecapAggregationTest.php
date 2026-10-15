<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use App\Services\LoanRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecapAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('pengurus');
        // guru, potong gaji, 12 bln: 1.200.000 + 120.000; siswa, tunai, 3 bln: 300.000; guru transfer lunas
        $this->loan('PJM-G', 'teacher', 'potong_gaji', 1200000, 120000, 12, '2026-03-05');
        $this->loan('PJM-S', 'student', 'tunai', 300000, 0, 3, '2026-08-20');
        $this->loan('PJM-LUNAS', 'teacher', 'transfer', 200000, 0, 1, '2026-06-01');
        $svc = app(LoanPaymentService::class);
        $this->travelTo('2026-07-02 10:00:00');
        $svc->record(Loan::where('code', 'PJM-LUNAS')->first(), ['amount' => 200000, 'paid_at' => '2026-07-01', 'payment_method' => 'transfer', 'reference' => 'TRX-L']);
        $this->travelTo('2026-09-10 10:00:00');
        $svc->record(Loan::where('code', 'PJM-G')->first(), ['amount' => 550000, 'paid_at' => '2026-09-10', 'payment_method' => 'potong_gaji', 'reference' => '2026-09']); // 5 × 110.000
    }

    private function loan(string $code, string $state, string $method, int $principal, int $interest, int $tenor, string $on): void
    {
        $this->travelTo($on.' 09:00:00');
        $m = $state === 'teacher' ? Member::factory()->teacher()->create(['name' => "Anggota {$code}"]) : Member::factory()->create(['name' => "Anggota {$code}", 'type' => 'siswa']);
        $loan = Loan::factory()->create(['code' => $code, 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => $principal, 'interest' => $interest,
            'tenor_months' => $tenor, 'payment_method' => $method, 'applied_at' => $on]);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => $on, 'amount' => $principal]);
    }

    public function test_running_loans_aggregation(): void
    {
        $r = app(LoanRecapService::class)->running();
        $this->assertSame(['count' => 2, 'principal' => 1500000, 'total_due' => 1620000, 'paid' => 550000, 'outstanding' => 1070000, 'average_outstanding' => 535000],
            array_intersect_key($r, array_flip(['count', 'principal', 'total_due', 'paid', 'outstanding', 'average_outstanding'])));
        $this->assertSame(['count' => 1, 'outstanding' => 770000], array_intersect_key($r['by_member_type']['guru'], array_flip(['count', 'outstanding'])));
        $this->assertSame(['count' => 1, 'outstanding' => 300000], array_intersect_key($r['by_member_type']['siswa'], array_flip(['count', 'outstanding'])));
        $this->assertSame([1, 0, 1], array_column($r['by_method'], 'count')); // potong gaji, transfer (lunas tidak dihitung), tunai
        $this->assertSame(['1' => 0, '2-3' => 1, '4-6' => 0, '7-12' => 1, '13+' => 0], array_map(fn ($b) => $b['count'], $r['by_remaining'])); // G: 7 tersisa, S: 3
        // s.d. 10 Okt: G sudah bayar ke-1..5 → ke-6 (5 Sep, lewat) + ke-7 (5 Okt) @110.000; S ke-1 (20 Sep) 100.000
        $this->assertSame(['installments' => 3, 'amount' => 320000], $r['expected_30_days']);
        $this->assertSame(['count' => 1, 'outstanding' => 770000], $r['overdue']); // G ke-6 (5 Sep) lewat jatuh tempo
    }

    public function test_months_use_grouped_queries_and_match_totals(): void
    {
        [$from, $to] = LoanRecapService::period('tahun', '2026');
        DB::enableQueryLog();
        $r = app(LoanRecapService::class)->recap($from, $to);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $months = collect($r['months'])->keyBy('month');
        $this->assertCount(12, $months);
        $this->assertSame([1200000, 200000, 300000], [$months['2026-03']['disbursed'], $months['2026-06']['disbursed'], $months['2026-08']['disbursed']]);
        $this->assertSame([200000, 1, 550000, 1], [$months['2026-07']['collected'], $months['2026-07']['count'], $months['2026-09']['collected'], $months['2026-09']['count']]);
        $this->assertSame($r['disbursed']['amount'], $months->sum('disbursed'));
        $this->assertSame($r['collected']['amount'], $months->sum('collected'));
        $this->assertLessThan(40, $queries, "rekap tahunan memakai {$queries} query");
        $this->assertArrayHasKey('running', $r);

        // rentang dipotong: 15 Mar – 15 Jul → pencairan 5 Mar tidak masuk, Juni masuk
        [$f, $t] = LoanRecapService::range('2026-03-15', '2026-07-15');
        $m = collect(app(LoanRecapService::class)->recap($f, $t)['months'])->pluck('disbursed', 'month')->all();
        $this->assertSame(['2026-03' => 0, '2026-04' => 0, '2026-05' => 0, '2026-06' => 200000, '2026-07' => 0], $m);
    }
}
