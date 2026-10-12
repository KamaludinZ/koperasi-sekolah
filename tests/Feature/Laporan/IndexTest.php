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
}
