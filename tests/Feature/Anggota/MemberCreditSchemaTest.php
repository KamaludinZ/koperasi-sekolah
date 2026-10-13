<?php

namespace Tests\Feature\Anggota;

use App\Models\Member;
use App\Models\MemberCredit;
use App\Models\MemberCreditAllocation;
use App\Models\MemberCreditPayment;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MemberCreditSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('member_credits', ['number', 'member_id', 'transaction_id', 'amount', 'paid', 'due_at', 'status', 'note', 'created_by', 'paid_off_at']));
        $this->assertTrue(Schema::hasColumns('member_credit_payments', ['number', 'member_id', 'amount', 'method', 'savings_transaction_id', 'note', 'paid_by', 'paid_at']));
        $this->assertTrue(Schema::hasColumns('member_credit_allocations', ['member_credit_payment_id', 'member_credit_id', 'amount']));
        $this->assertTrue(Schema::hasColumn('members', 'credit_limit'));
        $this->assertTrue(Schema::hasColumns('savings_transactions', ['source_type', 'source_id']));
    }

    public function test_credit_payment_allocation_roundtrip(): void
    {
        $m = Member::factory()->create(['balance' => 0]);
        $tx = Transaction::factory()->create(['member_id' => $m->id, 'payment_method' => 'bon']);
        $a = MemberCredit::create(['number' => 'BON-1', 'member_id' => $m->id, 'transaction_id' => $tx->id, 'amount' => 10000, 'due_at' => today()->subDay()]);
        $b = MemberCredit::create(['number' => 'BON-2', 'member_id' => $m->id, 'amount' => 5000, 'due_at' => today()->addWeek()]);

        $this->assertSame($a->id, $tx->credit->id);
        $this->assertSame(2, $m->credits()->open()->count());
        $this->assertSame([$a->id], MemberCredit::overdue()->pluck('id')->all());
        $this->assertTrue($a->isOverdue());

        $p = MemberCreditPayment::create(['number' => 'PLN-1', 'member_id' => $m->id, 'amount' => 12000, 'method' => 'tunai', 'paid_at' => now()]);
        $p->allocations()->createMany([['member_credit_id' => $a->id, 'amount' => 10000], ['member_credit_id' => $b->id, 'amount' => 2000]]);
        $a->syncPaid();
        $b->syncPaid();

        $this->assertSame(['lunas', 0, true], [$a->status, $a->remaining(), $a->paid_off_at !== null]);
        $this->assertSame(['sebagian', 3000], [$b->status, $b->remaining()]);
        $this->assertFalse($a->fresh()->isOverdue());
        $this->assertSame(2, $p->allocations()->count());

        $b->update(['status' => 'dibatalkan']);
        $b->syncPaid();
        $this->assertSame(['dibatalkan', 0], [$b->status, $b->remaining()]);
    }

    public function test_unique_and_restrict_constraints(): void
    {
        $m = Member::factory()->create();
        $tx = Transaction::factory()->create(['member_id' => $m->id]);
        MemberCredit::create(['number' => 'BON-1', 'member_id' => $m->id, 'transaction_id' => $tx->id, 'amount' => 1000, 'due_at' => today()]);

        $this->assertThrows(fn () => DB::transaction(fn () => MemberCredit::create(['number' => 'BON-1', 'member_id' => $m->id, 'amount' => 1000, 'due_at' => today()])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => MemberCredit::create(['number' => 'BON-9', 'member_id' => $m->id, 'transaction_id' => $tx->id, 'amount' => 1000, 'due_at' => today()])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => $m->forceDelete()), QueryException::class);
    }

    public function test_savings_source_columns_fillable(): void
    {
        $m = Member::factory()->create();
        $s = SavingsTransaction::create(['member_id' => $m->id, 'type' => 'setor', 'amount' => 5000, 'balance_before' => 0, 'balance_after' => 5000, 'source_type' => 'return', 'source_id' => 7]);
        $this->assertSame(['return', 7], [$s->fresh()->source_type, (int) $s->fresh()->source_id]);
        $m->update(['credit_limit' => 75000]);
        $this->assertSame(75000, $m->fresh()->credit_limit);
    }

    public function test_pgsql_check_constraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya di PostgreSQL.');
        }
        $m = Member::factory()->create();
        foreach ([
            fn () => MemberCredit::create(['number' => 'X1', 'member_id' => $m->id, 'amount' => 0, 'due_at' => today()]),
            fn () => MemberCredit::create(['number' => 'X2', 'member_id' => $m->id, 'amount' => 100, 'paid' => 200, 'due_at' => today()]),
            fn () => MemberCredit::create(['number' => 'X3', 'member_id' => $m->id, 'amount' => 100, 'status' => 'aneh', 'due_at' => today()]),
            fn () => MemberCreditPayment::create(['number' => 'P1', 'member_id' => $m->id, 'amount' => 100, 'method' => 'qris', 'paid_at' => now()]),
            fn () => $m->update(['credit_limit' => -1]),
        ] as $i => $bad) {
            $this->assertThrows(fn () => DB::transaction($bad), QueryException::class, null, "case $i");
        }
        $this->assertSame(0, MemberCreditAllocation::count());
    }
}
