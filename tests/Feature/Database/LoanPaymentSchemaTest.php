<?php

namespace Tests\Feature\Database;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoanPaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-S', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 900000,
            'interest' => 0, 'tenor_months' => 3, 'payment_method' => 'potong_gaji', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        $this->travelTo('2026-08-10 10:00:00');
    }

    private function row(array $over = []): array
    {
        return $over + ['loan_id' => $this->loan->id, 'paid_at' => '2026-08-05', 'amount' => 1000, 'payment_method' => 'tunai', 'reference' => null,
            'allocations' => '[]', 'created_at' => now(), 'updated_at' => now()];
    }

    private function rejects(callable $q, string $why): void
    {
        try {
            DB::transaction($q);
            $this->fail($why);
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_indexes_exist(): void
    {
        $names = collect(Schema::getIndexes('loan_payments'))->pluck('name');
        $this->assertTrue($names->contains('loan_payments_salary_month_unique'));
        $this->assertTrue($names->contains('loan_payments_transfer_ref_unique'));
        $this->assertTrue(collect(Schema::getIndexes('loans'))->contains(fn ($i) => $i['columns'] === ['member_id', 'applied_at']));
    }

    public function test_one_salary_month_per_loan_among_valid_payments(): void
    {
        DB::table('loan_payments')->insert($this->row(['payment_method' => 'potong_gaji', 'reference' => '2026-08']));
        $this->rejects(fn () => DB::table('loan_payments')->insert($this->row(['payment_method' => 'potong_gaji', 'reference' => '2026-08'])), 'Bulan gaji ganda harus ditolak.');

        DB::table('loan_payments')->where('reference', '2026-08')->update(['voided_at' => now(), 'void_reason' => 'Gagal potong']);
        DB::table('loan_payments')->insert($this->row(['payment_method' => 'potong_gaji', 'reference' => '2026-08'])); // yang batal tidak dihitung
        $this->assertSame(2, LoanPayment::count());

        $other = Loan::factory()->running()->create(['principal' => 100000, 'interest' => 0, 'outstanding' => 100000, 'tenor_months' => 1, 'disbursed_at' => '2026-07-01']);
        DB::table('loan_payments')->insert($this->row(['loan_id' => $other->id, 'payment_method' => 'potong_gaji', 'reference' => '2026-08'])); // pinjaman lain boleh
    }

    public function test_transfer_reference_unique_ignoring_case_and_spaces(): void
    {
        DB::table('loan_payments')->insert($this->row(['payment_method' => 'transfer', 'reference' => 'TRX 889']));
        $this->rejects(fn () => DB::table('loan_payments')->insert($this->row(['payment_method' => 'transfer', 'reference' => 'trx889'])), 'No. transfer ganda harus ditolak.');
        DB::table('loan_payments')->insert($this->row(['payment_method' => 'transfer', 'reference' => 'TRX 890']));
        $this->assertSame(2, LoanPayment::count());
    }

    public function test_pgsql_reference_must_match_method(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya di PostgreSQL.');
        }
        foreach ([
            ['payment_method' => 'potong_gaji', 'reference' => 'Agustus'],
            ['payment_method' => 'potong_gaji', 'reference' => null],
            ['payment_method' => 'transfer', 'reference' => null],
            ['payment_method' => 'transfer', 'reference' => ' a '],
            ['payment_method' => 'tunai', 'reference' => 'X-1'],
        ] as $i => $bad) {
            $this->rejects(fn () => DB::table('loan_payments')->insert($this->row($bad)), "Baris #{$i} seharusnya ditolak.");
        }
        $this->assertSame(0, LoanPayment::count());
    }

    public function test_race_on_unique_index_becomes_validation_error(): void
    {
        // pembayaran "petugas lain" masuk langsung ke tabel tanpa lewat pemeriksaan aplikasi, lalu aplikasi mencoba yang sama
        DB::table('loan_payments')->insert($this->row(['payment_method' => 'potong_gaji', 'reference' => '2026-08', 'voided_at' => null]));
        $svc = new class extends LoanPaymentService
        {
            public static function assertPaymentAllowed(Loan $loan, int $amount, int $outstanding, string $method, ?string $reference, ?int $exceptId = null): void {}
        };
        try {
            $svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-10', 'payment_method' => 'potong_gaji', 'reference' => '2026-08'], auth()->id());
            $this->fail('Seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertSame('Pembayaran dengan rujukan yang sama baru saja dicatat; muat ulang untuk melihatnya.', $e->errors()['reference'][0]);
        }
        $this->assertSame(1, LoanPayment::count());
        $this->assertSame(900000, (int) $this->loan->fresh()->outstanding); // transaksi dibatalkan utuh
    }
}
