<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanInstallmentChange;
use App\Models\Member;
use App\Services\LoanAuditChain;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LoanAuditChainTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-A', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 300000, 'interest' => 0,
            'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-09-25']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-10-01', 'amount' => 300000], auth()->id());
        $this->travelTo('2026-10-10 10:00:00');
        $svc = app(LoanPaymentService::class);
        $p = $svc->record($this->loan, ['amount' => 100000, 'paid_at' => '2026-10-10', 'payment_method' => 'tunai'], auth()->id());
        $svc->correct($p, 'Nominal salah ketik', ['amount' => 90000, 'paid_at' => '2026-10-10', 'payment_method' => 'tunai'], auth()->id());
    }

    private function chain(): LoanAuditChain
    {
        return app(LoanAuditChain::class);
    }

    public function test_every_loan_audit_row_and_installment_change_is_chained(): void
    {
        $this->assertTrue(Schema::hasColumns('loan_audit_chain', ['source_type', 'source_id', 'loan_id', 'payload_hash', 'prev_hmac', 'hmac', 'created_at']));
        $this->assertTrue(Schema::hasColumn('loan_installment_changes', 'checksum'));

        $activities = ActivityLog::where('category', 'pinjaman')->count();
        $changes = LoanInstallmentChange::count();
        $this->assertSame(3, $activities); // dicairkan, angsuran, koreksi
        $this->assertSame(2, $changes);
        $this->assertSame($activities + $changes, DB::table('loan_audit_chain')->count());
        $this->assertNull(DB::table('loan_audit_chain')->orderBy('id')->value('prev_hmac'));
        $this->assertTrue(LoanInstallmentChange::all()->every(fn ($c) => strlen((string) $c->checksum) === 64));
        $this->assertSame(0, DB::table('loan_audit_chain')->where('source_type', 'activity')->whereNotIn('source_id', ActivityLog::where('category', 'pinjaman')->select('id'))->count());

        $r = $this->chain()->verify();
        $this->assertTrue($r['valid']);
        $this->assertSame(5, $r['checked']);
        $this->assertSame(DB::table('loan_audit_chain')->orderByDesc('id')->value('hmac'), $r['last_hmac']);
        $this->assertSame(5, $this->chain()->verify($this->loan->id)['checked']);
        $this->assertSame(0, $this->chain()->verify(999)['checked']);
        ActivityLog::create(['event' => 'sale.created', 'category' => 'transaksi', 'description' => 'bukan pinjaman']);
        $this->assertSame(5, DB::table('loan_audit_chain')->count()); // kategori lain tidak dirantai
    }

    /** Simulasi pemilik database yang mematikan penjaga tabel untuk mengubah data diam-diam. */
    private function bypass(string $table, callable $fn): void
    {
        if ($table === 'activity_logs') {
            \App\Support\AuditGuard::suspend($fn);

            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            try {
                $fn();
            } finally {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }

            return;
        }
        DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update; DROP TRIGGER IF EXISTS {$table}_no_delete;");
        try {
            $fn();
        } finally {
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'x'); END;
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'x'); END;");
        }
    }

    public function test_detects_tampered_source_rows(): void
    {
        $fix = ActivityLog::where('event', 'pinjaman.angsuran_koreksi')->value('id');
        $this->bypass('activity_logs', fn () => DB::table('activity_logs')->where('id', $fix)->update(['reason' => 'diubah diam-diam']));
        $change = LoanInstallmentChange::orderBy('id')->value('id');
        $this->bypass('loan_installment_changes', fn () => DB::table('loan_installment_changes')->where('id', $change)->update(['actor_name' => 'Orang Lain']));

        $r = $this->chain()->verify();
        $this->assertFalse($r['valid']);
        $this->assertSame([], $r['broken']); // rantainya sendiri utuh — yang berubah sumbernya
        $this->assertEqualsCanonicalizing([['type' => 'activity', 'id' => $fix], ['type' => 'installment_change', 'id' => $change]], $r['tampered']);
    }

    public function test_detects_deleted_source_and_deleted_or_inserted_links(): void
    {
        $paid = ActivityLog::where('event', 'pinjaman.angsuran')->value('id');
        $this->bypass('activity_logs', fn () => DB::table('activity_logs')->where('id', $paid)->delete());
        $r = $this->chain()->verify();
        $this->assertSame([['type' => 'activity', 'id' => $paid]], $r['missing']);

        // hapus mata rantai di tengah → sambungan berikutnya putus
        $middle = DB::table('loan_audit_chain')->orderBy('id')->skip(2)->value('id');
        $this->bypass('loan_audit_chain', fn () => DB::table('loan_audit_chain')->where('id', $middle)->delete());
        $r = $this->chain()->verify();
        $this->assertNotEmpty($r['broken']);
        $this->assertFalse($r['valid']);
    }

    public function test_forged_link_and_unchained_rows_are_detected(): void
    {
        // baris jejak disisipkan langsung ke database (tidak lewat aplikasi → tidak dirantai)
        DB::table('activity_logs')->insert(['event' => 'pinjaman.angsuran', 'category' => 'pinjaman', 'description' => 'palsu', 'subject_type' => 'loan',
            'subject_id' => $this->loan->id, 'created_at' => now()]);
        $r = $this->chain()->verify();
        $this->assertCount(1, $r['unchained']);

        // mata rantai palsu tanpa kunci aplikasi
        $last = DB::table('loan_audit_chain')->orderByDesc('id')->first();
        $id = DB::table('activity_logs')->max('id');
        DB::table('loan_audit_chain')->insert(['source_type' => 'activity', 'source_id' => $id, 'loan_id' => $this->loan->id, 'payload_hash' => str_repeat('a', 64),
            'prev_hmac' => $last->hmac, 'hmac' => hash('sha256', 'tebakan'), 'created_at' => now()]);
        $r = $this->chain()->verify();
        $this->assertNotEmpty($r['broken']);
        $this->assertSame([], $r['unchained']);
    }

    public function test_backfill_is_idempotent_and_chain_is_append_only(): void
    {
        $this->assertSame(0, $this->chain()->backfill());
        $this->assertSame(5, DB::table('loan_audit_chain')->count());

        foreach ([fn () => DB::table('loan_audit_chain')->update(['loan_id' => null]), fn () => DB::table('loan_audit_chain')->delete(),
            fn () => DB::table('loan_installment_changes')->delete()] as $i => $q) { // penjaga hanya-tambah di SQLite & PostgreSQL
            try {
                DB::transaction($q);
                $this->fail("penjaga #{$i} seharusnya menolak");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                fn () => DB::table('loan_audit_chain')->insert(['source_type' => 'lain', 'source_id' => 1, 'payload_hash' => str_repeat('a', 64), 'hmac' => str_repeat('b', 64)])] as $i => $q) {
                try {
                    DB::transaction($q);
                    $this->fail("#{$i} seharusnya ditolak");
                } catch (QueryException) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertTrue($this->chain()->verify()['valid']);
    }
}
