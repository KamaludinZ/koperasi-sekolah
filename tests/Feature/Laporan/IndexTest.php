<?php

namespace Tests\Feature\Laporan;

use App\Livewire\Laporan\Index;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_renders_today_by_default(): void
    {
        $this->get('/laporan')
            ->assertOk()
            ->assertSee('Laporan & Rekap')
            ->assertSee('Total penjualan')
            ->assertSee('Barang terlaris');
    }

    public function test_longer_period_includes_more_sales(): void
    {
        $today = Livewire::test(Index::class)->instance()->summary;
        $month = Livewire::test(Index::class)->call('setPeriod', '30-hari')->instance()->summary;

        $this->assertGreaterThan($today['revenue'], $month['revenue']);
        $this->assertGreaterThan(0, $month['gross_profit']);
        $this->assertLessThan($month['revenue'], $month['gross_profit']);
    }

    public function test_top_products_sorted_by_quantity(): void
    {
        $top = Livewire::test(Index::class)->call('setPeriod', '30-hari')->instance()->topProducts;

        $this->assertLessThanOrEqual(5, count($top));
        $this->assertSame(array_column($top, 'quantity'), collect($top)->pluck('quantity')->sortDesc()->values()->all());
    }

    public function test_invalid_period_is_ignored(): void
    {
        Livewire::test(Index::class)->call('setPeriod', 'aneh')->assertSet('period', 'hari-ini');
    }

    public function test_today_card_is_independent_of_period(): void
    {
        $c = Livewire::test(Index::class)->assertSee('Penjualan hari ini');
        $today = $c->instance()->today;
        $c->call('setPeriod', '30-hari');

        $this->assertSame($today, $c->instance()->today);
        $this->assertSame($today['revenue'], array_sum($today['hourly']));
    }

    public function test_today_compares_with_yesterday_same_time(): void
    {
        $all = collect(\App\Support\MockData::transactions(30));
        $expected = $all->filter(fn ($t) => str_starts_with($t['created_at'], '2026-10-13') && substr($t['created_at'], 11) <= '15:00:00')->sum('total');

        $this->assertSame($expected, Livewire::test(Index::class)->instance()->today['compare']);
    }

    public function test_chart_points_per_day_and_week(): void
    {
        $c = Livewire::test(Index::class)->assertSee('Penjualan per hari')->assertSee('Lihat tabel data');
        $this->assertCount(7, $c->instance()->chartPoints);

        $c->call('setChartPeriod', '30-hari');
        $points = $c->instance()->chartPoints;
        $this->assertCount(30, $points);
        $this->assertSame(0, $points[array_search('Minggu, 11 Oktober 2026', array_column($points, 'tooltip'))]['value']); // Minggu tutup

        $c->call('setChartPeriod', '12-minggu')->assertSee('Penjualan per minggu');
        $this->assertCount(12, $c->instance()->chartPoints);
    }

    public function test_chart_total_matches_transactions(): void
    {
        $points = Livewire::test(Index::class)->call('setChartPeriod', '7-hari')->instance()->chartPoints;
        $expected = collect(\App\Support\MockData::transactions(90))
            ->filter(fn ($t) => $t['created_at'] >= '2026-10-08')
            ->sum('total');

        $this->assertSame($expected, array_sum(array_column($points, 'value')));
    }
}
