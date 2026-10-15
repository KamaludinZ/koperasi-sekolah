<?php

namespace Tests\Feature\Database;

use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Database\Seeders\LoanSeeder;
use Database\Seeders\MemberSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanOverdueSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function running(): Loan
    {
        $this->travelTo('2026-07-01 09:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $loan = Loan::factory()->create(['code' => 'PJM-O', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 300000, 'interest' => 0,
            'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-06-20']);

        return app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-07-01', 'amount' => 300000]);
    }

    public function test_column_and_index_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('loans', 'overdue_since'));
        $this->assertTrue(collect(Schema::getIndexes('loans'))->contains(fn ($i) => $i['columns'] === ['status', 'overdue_since']));
    }

    public function test_overdue_since_follows_oldest_unpaid_late_installment(): void
    {
        $loan = $this->running();
        $svc = app(LoanPaymentService::class);
        $this->assertNull($loan->fresh()->overdue_since);

        $this->travelTo('2026-09-05 10:00:00'); // ke-1 (1 Agt) & ke-2 (1 Sep) lewat
        $svc->recalculate($loan);
        $this->assertSame(['menunggak', '2026-08-01'], [$loan->fresh()->status, $loan->fresh()->overdue_since->toDateString()]);

        $svc->record($loan->fresh(), ['amount' => 100000, 'paid_at' => '2026-09-05', 'payment_method' => 'tunai']); // ke-1 lunas → tertua ke-2
        $this->assertSame(['menunggak', '2026-09-01'], [$loan->fresh()->status, $loan->fresh()->overdue_since->toDateString()]);

        $svc->record($loan->fresh(), ['amount' => 100000, 'paid_at' => '2026-09-05', 'payment_method' => 'tunai']); // tidak ada tunggakan
        $this->assertSame('berjalan', $loan->fresh()->status);
        $this->assertNull($loan->fresh()->overdue_since);

        $this->assertSame(['menunggak' => true], ['menunggak' => Loan::factory()->overdue()->create()->overdue_since !== null]);
    }

    public function test_seeded_overdue_loan_has_overdue_since(): void
    {
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([UserSeeder::class, MemberSeeder::class, LoanSeeder::class]);
        $this->assertTrue(Loan::where('status', 'menunggak')->get()->every(fn ($l) => $l->overdue_since !== null));
        $this->assertSame(0, Loan::where('status', '!=', 'menunggak')->whereNotNull('overdue_since')->count());
    }

    public function test_pgsql_check_ties_overdue_since_to_status(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya di PostgreSQL.');
        }
        $loan = $this->running();
        foreach ([['overdue_since' => '2026-08-01'], ['status' => 'menunggak', 'overdue_since' => null]] as $i => $bad) {
            try {
                DB::transaction(fn () => DB::table('loans')->where('id', $loan->id)->update($bad));
                $this->fail("#{$i} seharusnya ditolak");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        DB::table('loans')->where('id', $loan->id)->update(['status' => 'menunggak', 'overdue_since' => '2026-08-01']);
        $this->assertSame('menunggak', $loan->fresh()->status);
    }
}
