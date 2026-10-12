<?php

namespace Tests\Feature\Laporan;

use App\Models\Product;
use App\Models\Transaction;
use App\Services\SalesReport;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private SalesReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 10:00:00'); // Rabu
        $this->report = app(SalesReport::class);

        // Hari ini: 2× buku (modal 3.200, jual 4.000) + 1× pulpen (modal 2.400, jual 3.000).
        Transaction::factory()->withItems([$this->pid('ATK001') => 2])->at('2026-10-14 08:15:00')->create();
        Transaction::factory()->withItems([$this->pid('ATK002') => 1])->at('2026-10-14 09:40:00')->create();
        // Kemarin: sebelum & sesudah jam 10.
        Transaction::factory()->withItems([$this->pid('ATK001') => 1])->at('2026-10-13 09:00:00')->create();
        Transaction::factory()->withItems([$this->pid('ATK001') => 5])->at('2026-10-13 13:00:00')->create();
        // Bulan lalu.
        Transaction::factory()->withItems([$this->pid('ATK002') => 3])->at('2026-09-10 09:00:00')->create();
    }

    public function test_summary_with_gross_profit_and_margin(): void
    {
        $s = $this->report->summary(Carbon::parse('2026-10-14'), now());

        $this->assertSame(['revenue' => 11000, 'count' => 2, 'items' => 3, 'gross_profit' => 2200, 'margin' => 20, 'average' => 5500], $s);
        $this->assertSame(0, $this->report->summary(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'))['margin']);
    }

    public function test_profit_uses_cost_snapshot_not_current_cost(): void
    {
        Product::whereKey($this->pid('ATK001'))->update(['cost_price' => 4000]);

        $this->assertSame(2200, $this->report->summary(Carbon::parse('2026-10-14'), now())['gross_profit']);
    }

    public function test_period_report_compares_equivalent_range(): void
    {
        $r = $this->report->periodReport('hari-ini');

        $this->assertSame(4000, $r['comparison']['previous']['revenue']); // kemarin s.d. jam 10
        $this->assertSame(175, $r['comparison']['changes']['revenue']);
        $this->assertSame('kemarin jam segini', $r['comparison']['label']);

        $month = $this->report->periodReport('bulan-ini');
        $this->assertSame(35000, $month['summary']['revenue']);
        $this->assertSame(9000, $month['comparison']['previous']['revenue']);
    }

    public function test_today_card(): void
    {
        $t = $this->report->today();

        $this->assertSame(11000, $t['revenue']);
        $this->assertSame(4000, $t['compare']);
        $this->assertSame(['name' => 'Buku Tulis 38 Lembar', 'quantity' => 2], $t['best']);
        $this->assertSame(8000, $t['hourly'][8]);
        $this->assertSame(3000, $t['hourly'][9]);
        $this->assertSame(11000, array_sum($t['hourly']));
        $this->assertSame('2026-10-14 09:40:00', $t['last_at']);
        $this->assertSame(10, $t['current_hour']);
    }

    public function test_top_products_and_daily_recap(): void
    {
        $top = $this->report->topProducts(Carbon::parse('2026-10-01'), now());
        $this->assertSame(['name' => 'Buku Tulis 38 Lembar', 'quantity' => 8, 'revenue' => 32000, 'profit' => 6400], $top[0]);
        $this->assertSame('Pulpen Hitam', $top[1]['name']);

        $daily = $this->report->daily(Carbon::parse('2026-10-01'), now());
        $this->assertSame([
            ['date' => '2026-10-13', 'count' => 2, 'items' => 6, 'revenue' => 24000],
            ['date' => '2026-10-14', 'count' => 2, 'items' => 3, 'revenue' => 11000],
        ], $daily);
    }

    public function test_chart_points_fill_empty_days_and_group_weeks(): void
    {
        $days = $this->report->chartPoints('7-hari');
        $this->assertCount(7, $days);
        $this->assertSame('2026-10-08', $days[0]['date']);
        $this->assertSame(24000, $days[5]['value']);
        $this->assertSame(0, $days[0]['value']);

        $weeks = $this->report->chartPoints('12-minggu');
        $this->assertCount(12, $weeks);
        $this->assertSame('2026-10-12', end($weeks)['date']); // Senin minggu ini
        $this->assertSame(35000, end($weeks)['value']);
        $this->assertSame(4, end($weeks)['count']);
    }
}
