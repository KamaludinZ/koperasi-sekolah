<?php

namespace Tests\Feature\Database;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use Database\Seeders\LoanSeeder;
use Database\Seeders\MemberSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('loans', ['code', 'member_id', 'principal', 'interest', 'tenor_months', 'payment_method', 'status',
            'purpose', 'decision_note', 'outstanding', 'applied_at', 'decided_at', 'decided_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
            'disbursed_at', 'paid_off_at', 'created_by']));
        $this->assertTrue(Schema::hasColumns('loan_disbursements', ['loan_id', 'disbursed_on', 'amount', 'proof', 'proof_file', 'proof_mime', 'proof_size', 'user_id']));
        $this->assertTrue(Schema::hasColumns('loan_installments', ['loan_id', 'sequence', 'due_date', 'amount', 'paid_amount', 'paid_at',
            'payment_method', 'status', 'note', 'user_id']));
    }

    public function test_seeder_builds_consistent_demo_loans(): void
    {
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([UserSeeder::class, MemberSeeder::class, LoanSeeder::class]);

        $this->assertSame(['berjalan', 'diajukan', 'disetujui', 'lunas', 'menunggak'], Loan::orderBy('status')->pluck('status')->all());
        foreach (Loan::with('installments', 'disbursements', 'member')->get() as $loan) {
            $this->assertSame('guru', $loan->member->type);
            $this->assertMatchesRegularExpression('/^PJM-2026-\d{4}$/', $loan->code);
            if ($loan->disbursed_at) {
                $this->assertCount($loan->tenor_months, $loan->installments);
                $this->assertSame($loan->totalDue(), (int) $loan->installments->sum(fn ($i) => (float) $i->amount));
                $paid = (int) $loan->installments->sum(fn ($i) => (float) $i->paid_amount);
                $this->assertSame($loan->totalDue() - $paid, (int) (float) $loan->outstanding);
                $this->assertSame((int) (float) $loan->principal, (int) (float) $loan->disbursements->sum('amount'));
            } else {
                $this->assertCount(0, $loan->installments);
            }
        }
        $this->assertTrue(Loan::where('status', 'menunggak')->first()->installments->contains('status', 'menunggak'));
        $this->assertFalse(Loan::where('status', 'berjalan')->first()->installments->contains('status', 'menunggak'));
        $this->assertSame(2, Loan::active()->count());

        $this->seed(LoanSeeder::class); // tidak menggandakan
        $this->assertSame(5, Loan::count());
    }

    public function test_next_code_per_year(): void
    {
        $this->assertSame('PJM-2026-0001', Loan::nextCode(now()->setDate(2026, 3, 1)));
        Loan::factory()->create(['code' => 'PJM-2026-0009']);
        Loan::factory()->create(['code' => 'PJM-2025-0042']);
        $this->assertSame('PJM-2026-0010', Loan::nextCode(now()->setDate(2026, 3, 1)));
        $this->assertSame('PJM-2025-0043', Loan::nextCode(now()->setDate(2025, 12, 1)));
        $this->assertSame('PJM-2027-0001', Loan::nextCode(now()->setDate(2027, 1, 1)));
    }

    public function test_member_relation_and_member_delete_protection(): void
    {
        $member = Member::factory()->teacher()->create();
        Loan::factory()->count(2)->create(['member_id' => $member->id]);
        $this->assertSame(2, $member->loans()->count());

        $this->expectException(QueryException::class);
        DB::table('members')->where('id', $member->id)->delete(); // restrictOnDelete
    }

    public function test_installments_unique_per_sequence_and_cascade(): void
    {
        $loan = Loan::factory()->running()->create(['principal' => 100000, 'interest' => 0, 'outstanding' => 100000, 'tenor_months' => 1]);
        LoanInstallment::create(['loan_id' => $loan->id, 'sequence' => 1, 'due_date' => today(), 'amount' => 100000]);
        try {
            DB::transaction(fn () => LoanInstallment::create(['loan_id' => $loan->id, 'sequence' => 1, 'due_date' => today(), 'amount' => 100000]));
            $this->fail('Angsuran ke-1 ganda seharusnya ditolak.');
        } catch (QueryException) {
        }
        $loan->delete();
        $this->assertSame(0, LoanInstallment::count());
    }

    public function test_pgsql_checks_reject_inconsistent_rows(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya di PostgreSQL.');
        }
        $loan = Loan::factory()->running()->create(['principal' => 100000, 'interest' => 10000, 'outstanding' => 110000, 'tenor_months' => 2]);
        $inst = LoanInstallment::create(['loan_id' => $loan->id, 'sequence' => 1, 'due_date' => today(), 'amount' => 55000]);

        $bad = [
            fn () => DB::table('loans')->where('id', $loan->id)->update(['outstanding' => 110001]),
            fn () => DB::table('loans')->where('id', $loan->id)->update(['status' => 'lunas']),
            fn () => DB::table('loans')->where('id', $loan->id)->update(['disbursed_at' => null]),
            fn () => DB::table('loans')->where('id', $loan->id)->update(['status' => 'macet']),
            fn () => DB::table('loans')->where('id', $loan->id)->update(['tenor_months' => 0]),
            fn () => DB::table('loan_installments')->where('id', $inst->id)->update(['paid_amount' => 55001]),
            fn () => DB::table('loans')->insert(['code' => 'PJM-X-1', 'member_id' => $loan->member_id, 'principal' => 1, 'tenor_months' => 1, 'payment_method' => 'tunai', 'status' => 'dibatalkan', 'applied_at' => today()]),
            fn () => DB::table('loans')->insert(['code' => 'PJM-X-2', 'member_id' => $loan->member_id, 'principal' => 1, 'tenor_months' => 1, 'payment_method' => 'tunai', 'status' => 'disetujui', 'applied_at' => today()]),
            fn () => DB::table('loans')->insert(['code' => 'PJM-X-3', 'member_id' => $loan->member_id, 'principal' => 1, 'tenor_months' => 1, 'payment_method' => 'tunai', 'status' => 'diajukan', 'applied_at' => today(), 'cancelled_at' => now()]),
            fn () => DB::table('loan_installments')->where('id', $inst->id)->update(['status' => 'dibayar']),
            fn () => DB::table('loan_installments')->where('id', $inst->id)->update(['payment_method' => 'kartu']),
        ];
        foreach ($bad as $i => $q) {
            try {
                DB::transaction($q);
                $this->fail("Perubahan #{$i} seharusnya ditolak CHECK.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        DB::table('loan_installments')->where('id', $inst->id)->update(['status' => 'dibayar', 'paid_amount' => 55000, 'paid_at' => today()]);
        DB::table('loans')->where('id', $loan->id)->update(['outstanding' => 55000]);
        $this->assertSame('dibayar', $inst->fresh()->status);
    }

    public function test_one_disbursement_per_loan_and_proof_path_guard(): void
    {
        $loan = Loan::factory()->running()->create(['principal' => 100000, 'interest' => 0, 'outstanding' => 100000, 'tenor_months' => 1]);
        \App\Models\LoanDisbursement::create(['loan_id' => $loan->id, 'disbursed_on' => today(), 'amount' => 100000]);
        try {
            DB::transaction(fn () => \App\Models\LoanDisbursement::create(['loan_id' => $loan->id, 'disbursed_on' => today(), 'amount' => 100000]));
            $this->fail('Pencairan kedua untuk pinjaman yang sama harus ditolak.');
        } catch (QueryException) {
            $this->assertSame(1, $loan->disbursements()->count());
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['../../.env', 'pinjaman/bukti/../../x.jpg', 'lain/x.jpg'] as $path) {
                try {
                    DB::transaction(fn () => DB::table('loan_disbursements')->where('loan_id', $loan->id)->update(['proof_file' => $path]));
                    $this->fail("Path {$path} harus ditolak.");
                } catch (QueryException) {
                }
            }
            DB::table('loan_disbursements')->where('loan_id', $loan->id)->update(['proof_file' => 'pinjaman/bukti/ok.jpg', 'proof_size' => 10]);
        }
    }
}
