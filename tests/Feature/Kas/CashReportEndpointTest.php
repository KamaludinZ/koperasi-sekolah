<?php

namespace Tests\Feature\Kas;

use App\Models\CashShift;
use App\Models\Transaction;
use App\Services\CashReportService;
use Database\Seeders\CashShiftSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CashReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00'); // Rabu siang: shift siang berjalan
        $this->seed([ProductSeeder::class, TransactionSeeder::class, CashShiftSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_day_report_matches_models(): void
    {
        $day = $this->getJson('/kas/rekap/harian?tanggal=2026-10-13')->assertOk()->json('data');

        $this->assertSame('2026-10-13', $day['date']);
        $this->assertFalse($day['is_today']);
        $this->assertCount(2, $day['shifts']);
        foreach ($day['shifts'] as $row) {
            $shift = CashShift::find($row['id']);
            $this->assertSame($shift->cashSales(), $row['cash_sales']);
            $this->assertSame((int) $shift->expected_cash, $row['expected']);
            $this->assertSame($row['counted'] - $row['expected'], $row['difference']);
        }
        $this->assertSame(array_sum(array_column($day['shifts'], 'difference')), $day['summary']['difference']);
        $this->assertSame(
            (int) Transaction::whereBetween('created_at', ['2026-10-13 00:00:00', '2026-10-13 23:59:59'])->sum('total'),
            $day['summary']['revenue'],
        );
    }

    public function test_today_has_running_shift_without_count(): void
    {
        $day = $this->getJson('/kas/rekap/harian')->assertOk()->json('data');
        $running = collect($day['shifts'])->firstWhere('status', 'open');

        $this->assertTrue($day['is_today']);
        $this->assertNull($running['counted']);
        $this->assertSame(CashShift::find($running['id'])->expectedCash(), $running['expected']);
        $this->assertSame(1, $day['summary']['open_shifts']);
    }

    public function test_sunday_is_closed_day_and_future_rejected(): void
    {
        $this->getJson('/kas/rekap/harian?tanggal=2026-10-11')->assertOk()->assertJsonPath('data.closed_day', true)->assertJsonPath('data.shifts', []);
        $this->getJson('/kas/rekap/harian?tanggal=2026-10-15')->assertUnprocessable();
    }

    public function test_sales_without_shift_are_flagged(): void
    {
        $day = app(CashReportService::class)->day('2026-09-01'); // sebelum riwayat shift
        $this->assertSame([], $day['shifts']);
        $this->assertSame($day['sales']['tunai'], $day['unassigned_cash_sales']);
    }

    public function test_period_report_rows_and_totals(): void
    {
        $res = $this->getJson('/kas/rekap/periode?periode=7-hari')->assertOk();
        $rows = $res->json('data');

        $this->assertCount(7, $rows);
        $this->assertSame('2026-10-14', $rows[0]['date']);
        $this->assertTrue(collect($rows)->firstWhere('date', '2026-10-11')['closed_day']);
        $this->assertSame(array_sum(array_column($rows, 'difference')), $res->json('meta.totals.difference'));
        $this->assertSame($res->json('meta.totals.difference'), $res->json('meta.totals.short') + $res->json('meta.totals.over'));

        $day = app(CashReportService::class)->day('2026-10-12')['summary'];
        $row = collect($rows)->firstWhere('date', '2026-10-12');
        $this->assertSame([$day['expected'], $day['cash_sales'], $day['revenue']], [$row['expected'], $row['cash_sales'], $row['revenue']]);
    }

    public function test_period_custom_range_validation(): void
    {
        $this->getJson('/kas/rekap/periode?dari=2026-10-01&sampai=2026-10-05')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/kas/rekap/periode?dari=2026-10-05&sampai=2026-10-01')->assertJsonValidationErrors('sampai');
        $this->getJson('/kas/rekap/periode?dari=2026-01-01&sampai=2026-10-01')->assertJsonValidationErrors('dari');
        $this->getJson('/kas/rekap/periode?periode=setahun')->assertJsonValidationErrors('periode');
    }

    public function test_cashier_and_pengurus_can_read(): void
    {
        $this->loginAs('kasir');
        $this->getJson('/kas/rekap/harian')->assertOk();
        $this->loginAs('pengurus');
        $this->getJson('/kas/rekap/periode')->assertOk();
    }
}
