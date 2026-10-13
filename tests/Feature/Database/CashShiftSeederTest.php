<?php

namespace Tests\Feature\Database;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Transaction;
use Database\Seeders\CashShiftSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CashShiftSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00'); // Rabu siang: shift siang masih berjalan
        $this->seed([ProductSeeder::class, TransactionSeeder::class, CashShiftSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_seeds_two_shifts_per_open_day_with_one_running(): void
    {
        $this->assertSame(24, CashShift::count()); // 12 hari buka × 2 shift dalam 14 hari
        $this->assertSame(0, CashShift::onDate('2026-10-11')->count()); // Minggu
        $open = CashShift::open()->sole();
        $this->assertSame(['Shift siang', '2026-10-14'], [$open->name, $open->opened_at->toDateString()]);
    }

    public function test_closed_shifts_are_consistent_with_sales_and_movements(): void
    {
        foreach (CashShift::where('status', 'closed')->get() as $shift) {
            $this->assertSame((int) $shift->expected_cash, $shift->expectedCash(), "Shift #{$shift->id}");
            $this->assertSame((int) $shift->counted_cash - (int) $shift->expected_cash, (int) $shift->difference);
            $this->assertTrue((int) $shift->difference === 0 || filled($shift->close_note), json_encode($shift->only("difference", "close_note", "counted_cash", "expected_cash")));
        }
        $this->assertGreaterThan(0, Transaction::whereNotNull('cash_shift_id')->count());
        $this->assertGreaterThan(0, CashMovement::count());
    }

    public function test_daily_totals_and_skip_when_seeded(): void
    {
        $date = CashMovement::orderBy('created_at')->first()->created_at;
        $totals = CashMovement::dailyTotals($date);
        $rows = CashMovement::onDate($date)->get();

        $this->assertSame((int) $rows->where('type', 'in')->sum('amount'), $totals['in']);
        $this->assertSame((int) $rows->where('type', 'out')->sum('amount'), $totals['out']);
        $this->assertSame($rows->count(), $totals['count']);
        $this->assertSame($totals['in'], array_sum(array_column($totals['by_category'], 'in')));

        $this->seed(CashShiftSeeder::class);
        $this->assertSame(24, CashShift::count());
    }
}
