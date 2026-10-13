<?php

namespace Tests\Feature\Database;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashShiftSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('cash_shifts', ['name', 'status', 'user_id', 'closed_by', 'opened_at', 'closed_at', 'opening_cash', 'expected_cash', 'counted_cash', 'difference', 'close_note']));
        $this->assertTrue(Schema::hasColumns('cash_movements', ['cash_shift_id', 'type', 'amount', 'reason', 'user_id', 'created_at']));
        $this->assertTrue(Schema::hasColumn('transactions', 'cash_shift_id'));
    }

    public function test_only_one_open_shift_allowed(): void
    {
        CashShift::factory()->create();
        CashShift::factory()->closed()->create();
        CashShift::factory()->closed(-500)->create();

        $this->expectException(QueryException::class);
        CashShift::factory()->create(['name' => 'Shift siang']);
    }

    public function test_expected_cash_from_sales_and_movements(): void
    {
        $shift = CashShift::factory()->create(['opening_cash' => 150000]);
        Transaction::factory()->create(['cash_shift_id' => $shift->id, 'payment_method' => 'tunai', 'total' => 25000]);
        Transaction::factory()->create(['cash_shift_id' => $shift->id, 'payment_method' => 'qris', 'total' => 10000]);
        Transaction::factory()->create(['payment_method' => 'tunai', 'total' => 99000]); // tanpa shift
        CashMovement::factory()->for($shift, 'shift')->create(['amount' => 50000]);
        CashMovement::factory()->for($shift, 'shift')->out(20000)->create();

        $shift->refresh();
        $this->assertSame(25000, $shift->cashSales());
        $this->assertSame(10000, $shift->nonCashSales());
        $this->assertSame([50000, 20000], [$shift->movementTotal('in'), $shift->movementTotal('out')]);
        $this->assertSame(150000 + 25000 + 50000 - 20000, $shift->expectedCash());
        $this->assertSame([-20000, 50000], $shift->movements()->orderBy('type', 'desc')->get()->map->signed_amount->all());
        $this->assertTrue($shift->isOpen());
        $this->assertSame(2, $shift->transactions()->count());
    }

    public function test_scopes_and_relations(): void
    {
        $user = auth()->user();
        $open = CashShift::factory()->create(['user_id' => $user->id]);
        CashShift::factory()->closed()->create(['opened_at' => now()->subDays(2), 'closed_at' => now()->subDays(2)->addHours(4)]);

        $this->assertSame([$open->id], CashShift::open()->pluck('id')->all());
        $this->assertSame(1, CashShift::onDate(today())->count());
        $this->assertSame($user->id, $open->user->id);
    }

    public function test_movement_cannot_orphan_shift(): void
    {
        $shift = CashShift::factory()->create();
        CashMovement::factory()->for($shift, 'shift')->create();

        $this->expectException(QueryException::class);
        $shift->delete();
    }

    public function test_postgres_constraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint CHECK hanya di PostgreSQL.');
        }

        $shift = CashShift::factory()->create();
        foreach ([
            fn () => CashShift::factory()->closed()->create(['difference' => 999]),            // selisih tidak konsisten
            fn () => $shift->update(['status' => 'closed']),                                   // tutup tanpa hitungan
            fn () => CashMovement::factory()->for($shift, 'shift')->create(['amount' => 0]),
            fn () => CashMovement::factory()->for($shift, 'shift')->create(['type' => 'x']),
            fn () => CashShift::factory()->closed()->create(['opening_cash' => -1]),
        ] as $i => $bad) {
            try {
                DB::transaction($bad);
                $this->fail("Constraint #{$i} tidak menolak data salah.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }
}
