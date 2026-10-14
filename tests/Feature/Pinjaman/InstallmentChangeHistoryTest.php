<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanInstallmentChange;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class InstallmentChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    private LoanPaymentService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->loginAs('pengurus');
        $member = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-H', 'member_id' => $member->id, 'status' => 'disetujui', 'principal' => 900000,
            'interest' => 0, 'tenor_months' => 3, 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        $this->svc = app(LoanPaymentService::class);
    }

    public function test_table_exists_and_schedule_creation_is_not_logged(): void
    {
        $this->assertTrue(Schema::hasColumns('loan_installment_changes', ['loan_id', 'loan_installment_id', 'sequence', 'event', 'changes',
            'loan_payment_id', 'reason', 'user_id', 'actor_name', 'created_at']));
        $this->assertSame(0, LoanInstallmentChange::count());
    }

    public function test_payment_logs_before_after_per_installment_with_payment_and_actor(): void
    {
        $this->travelTo('2026-08-05 10:00:00');
        $p = $this->svc->record($this->loan, ['amount' => 450000, 'paid_at' => '2026-08-05', 'payment_method' => 'transfer', 'reference' => 'TRX-1'], auth()->id());

        $rows = $this->loan->installmentChanges()->get();
        $this->assertSame([1, 2], $rows->pluck('sequence')->all());
        $this->assertSame(['pembayaran', 'pembayaran'], $rows->pluck('event')->all());
        $this->assertSame([$p->id, $p->id], $rows->pluck('loan_payment_id')->all());
        $this->assertSame(auth()->user()->name, $rows[0]->actor_name);
        $this->assertSame(['from' => 0, 'to' => 300000], $rows[0]->changes['paid_amount']);
        $this->assertSame(['from' => 'belum', 'to' => 'dibayar'], $rows[0]->changes['status']);
        $this->assertSame(['from' => null, 'to' => '2026-08-05'], $rows[0]->changes['paid_at']);
        $this->assertSame(['from' => null, 'to' => 'transfer'], $rows[0]->changes['payment_method']);
        $this->assertSame(['from' => 0, 'to' => 150000], $rows[1]->changes['paid_amount']);
        $this->assertArrayNotHasKey('status', $rows[1]->changes); // sebagian: tetap "belum"
        $this->assertSame(1, $this->loan->installments()->where('sequence', 1)->first()->history()->count());
        $this->assertSame(0, $this->loan->installments()->where('sequence', 3)->first()->history()->count());
    }

    public function test_overdue_status_change_is_logged_as_status(): void
    {
        $this->travelTo('2026-08-10 10:00:00');
        $this->svc->refreshLoan($this->loan->fresh());

        $row = $this->loan->installmentChanges()->sole();
        $this->assertSame('status', $row->event);
        $this->assertSame(1, $row->sequence);
        $this->assertSame(['status' => ['from' => 'belum', 'to' => 'menunggak']], $row->changes);
        $this->assertNull($row->loan_payment_id);

        $this->svc->refreshLoan($this->loan->fresh()); // tidak berubah → tidak ada baris baru
        $this->assertSame(1, LoanInstallmentChange::count());
    }

    public function test_correction_logs_net_difference_with_reason_only(): void
    {
        $this->travelTo('2026-08-05 10:00:00');
        $wrong = $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'], auth()->id());
        $this->svc->record($this->loan->fresh(), ['amount' => 100000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'], auth()->id());
        $lastId = LoanInstallmentChange::max('id');

        $this->svc->correct($wrong, 'Salah input nominal', ['amount' => 200000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'], auth()->id());

        $new = LoanInstallmentChange::where('id', '>', $lastId)->orderBy('id')->get();
        $this->assertSame(['koreksi'], $new->pluck('event')->all()); // putar ulang tidak mencatat baris per simpan
        $this->assertTrue($new->every(fn ($r) => $r->reason === 'Salah input nominal' && $r->loan_payment_id === $wrong->id));
        // sebelum: ke-1 lunas 300rb, ke-2 100rb. Sesudah (200rb + 100rb): ke-1 lunas 300rb (tidak berubah), ke-2 0.
        $this->assertSame([2], $new->pluck('sequence')->all());
        $this->assertSame(['from' => 100000, 'to' => 0], $new[0]->changes['paid_amount']);
        $this->assertSame(['from' => '2026-08-05', 'to' => null], $new[0]->changes['paid_at']);
    }

    public function test_history_is_append_only(): void
    {
        $this->travelTo('2026-08-05 10:00:00');
        $this->svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai'], auth()->id());
        $row = LoanInstallmentChange::first();

        foreach ([fn () => $row->update(['reason' => 'ubah']), fn () => $row->delete()] as $op) {
            try {
                $op();
                $this->fail('Riwayat seharusnya tidak bisa diubah/dihapus.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        try {
            DB::transaction(fn () => $this->loan->delete());
            $this->fail('Pinjaman dengan riwayat angsuran tidak boleh terhapus.');
        } catch (QueryException) {
            $this->assertTrue(Loan::whereKey($this->loan->id)->exists());
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                fn () => DB::table('loan_installment_changes')->where('id', $row->id)->update(['reason' => 'x']),
                fn () => DB::table('loan_installment_changes')->where('id', $row->id)->delete(),
                fn () => DB::table('loan_installment_changes')->insert(['loan_id' => $this->loan->id, 'loan_installment_id' => 1, 'sequence' => 1, 'event' => 'hapus', 'changes' => '{}']),
                fn () => DB::table('loan_installment_changes')->insert(['loan_id' => $this->loan->id, 'loan_installment_id' => 1, 'sequence' => 1, 'event' => 'koreksi', 'changes' => '{}']),
            ] as $i => $q) {
                try {
                    DB::transaction($q);
                    $this->fail("Operasi #{$i} seharusnya ditolak database.");
                } catch (QueryException) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertSame(1, LoanInstallmentChange::whereKey($row->id)->count());
    }

    public function test_schedule_change_is_logged_as_jadwal(): void
    {
        $i = $this->loan->installments()->where('sequence', 3)->first();
        $i->update(['due_date' => '2026-10-05']);
        $row = $i->history()->sole();
        $this->assertSame('jadwal', $row->event);
        $this->assertSame(['due_date' => ['from' => '2026-10-01', 'to' => '2026-10-05']], $row->changes);
    }
}
