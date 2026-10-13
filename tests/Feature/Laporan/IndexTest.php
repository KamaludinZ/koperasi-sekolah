<?php

namespace Tests\Feature\Laporan;

use App\Livewire\Laporan\Index;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
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
        $this->seed([ProductSeeder::class, TransactionSeeder::class]); // ±90 hari riwayat contoh
    }

    private function revenue(string $from, string $to): int
    {
        return (int) Transaction::whereBetween('created_at', [$from, $to])->sum('total');
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
        $expected = $this->revenue('2026-10-13 00:00:00', '2026-10-13 15:00:00');

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
        $expected = $this->revenue('2026-10-08 00:00:00', '2026-10-14 15:00:00');

        $this->assertSame($expected, array_sum(array_column($points, 'value')));
    }

    public function test_comparison_uses_equivalent_previous_range(): void
    {
        $c = Livewire::test(Index::class)->call('setPeriod', '7-hari');
        [$start, $end] = $c->instance()->previousRange;
        $this->assertSame('2026-10-01 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-10-07 15:00:00', $end->toDateTimeString());

        $expected = $this->revenue('2026-10-01 00:00:00', '2026-10-07 15:00:00');
        $cmp = $c->instance()->comparison;
        $this->assertSame($expected, $cmp['previous']['revenue']);
        $this->assertSame(
            (int) round(($c->instance()->summary['revenue'] - $expected) / $expected * 100),
            $cmp['changes']['revenue'],
        );
        $c->assertSee('Dibandingkan dengan 7 hari sebelumnya');
    }

    public function test_month_compares_with_same_dates_last_month(): void
    {
        [$start, $end] = Livewire::test(Index::class)->call('setPeriod', 'bulan-ini')->instance()->previousRange;

        $this->assertSame('2026-09-01', $start->toDateString());
        $this->assertSame('2026-09-14 15:00:00', $end->toDateTimeString());
    }

    public function test_export_range_follows_period(): void
    {
        Livewire::test(Index::class)
            ->assertSet('exportFrom', '2026-10-14')
            ->call('setPeriod', '7-hari')
            ->assertSet('exportFrom', '2026-10-08')
            ->assertSet('exportTo', '2026-10-14');
    }

    public function test_export_downloads_csv_with_selected_sections(): void
    {
        $c = Livewire::test(Index::class)
            ->set('exportFrom', '2026-10-01')
            ->set('exportTo', '2026-10-14')
            ->set('exportSections', ['summary', 'transactions'])
            ->call('export')
            ->assertHasNoErrors()
            ->assertFileDownloaded('rekap-penjualan-20261001-20261014.csv');

        $csv = base64_decode($c->effects['download']['content']);
        $this->assertStringContainsString('RINGKASAN', $csv);
        $this->assertStringContainsString('RINCIAN TRANSAKSI', $csv);
        $this->assertStringNotContainsString('BARANG TERJUAL', $csv);

        $expected = $this->revenue('2026-10-01 00:00:00', '2026-10-14 23:59:59');
        $this->assertStringContainsString('"Total penjualan";'.$expected, $csv);
    }

    public function test_export_validation(): void
    {
        Livewire::test(Index::class)
            ->set('exportFrom', '2026-10-10')
            ->set('exportTo', '2026-10-01')
            ->set('exportSections', [])
            ->call('export')
            ->assertHasErrors(['exportTo', 'exportSections'])
            ->assertNoFileDownloaded();

        Livewire::test(Index::class)
            ->set('exportTo', '2026-12-01')
            ->call('export')
            ->assertHasErrors(['exportTo' => 'before_or_equal']);
    }

    public function test_custom_date_range(): void
    {
        \App\Models\Transaction::factory()->withItems([$this->pid('ATK001') => 2])->at(now()->subDays(10)->setTime(10, 0)->toDateTimeString())->create();
        \App\Models\Transaction::factory()->withItems([$this->pid('ATK001') => 1])->at(now()->subDays(20)->setTime(10, 0)->toDateTimeString())->create();
        $from = now()->subDays(12)->toDateString();
        $to = now()->subDays(8)->toDateString();

        $c = \Livewire\Livewire::test(\App\Livewire\Laporan\Index::class)->set('from', $from)->set('to', $to)->call('applyCustom')
            ->assertHasNoErrors()->assertSet('period', 'kustom')->assertSet('exportFrom', $from)->assertSet('exportTo', $to);
        $expected = app(\App\Services\SalesReport::class)->summary(Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay());
        $this->assertSame($expected, $c->instance()->summary);
        $this->assertGreaterThanOrEqual(1, $c->instance()->summary['count']);
        $this->assertSame('5 hari sebelumnya', $c->instance()->comparison['label']);
        $prev = app(\App\Services\SalesReport::class)->summary(Carbon::parse($from)->subDays(5)->startOfDay(), Carbon::parse($from)->startOfDay()->subSecond());
        $this->assertSame($prev['count'], $c->instance()->comparison['previous']['count']);
        $c->assertSee('Rekap periode: '.Carbon::parse($from)->translatedFormat('d M'));

        $c->set('to', now()->subDays(20)->toDateString())->call('applyCustom')->assertHasErrors('to');
        $c->set('from', now()->subDays(400)->toDateString())->set('to', now()->toDateString())->call('applyCustom')->assertHasErrors('to');
        $c->call('setPeriod', '7-hari')->assertSet('from', '')->assertSet('period', '7-hari');

        \Livewire\Livewire::withQueryParams(['periode' => 'kustom', 'dari' => $from, 'sampai' => $to])->test(\App\Livewire\Laporan\Index::class)->assertSet('period', 'kustom');
        \Livewire\Livewire::withQueryParams(['periode' => 'kustom', 'dari' => 'x'])->test(\App\Livewire\Laporan\Index::class)->assertSet('period', 'hari-ini');
    }

    public function test_sold_products_table_and_transactions_link(): void
    {
        $c = Livewire::test(Index::class)->call('setPeriod', '30-hari');
        $sold = $c->instance()->soldProducts;
        $this->assertNotEmpty($sold);
        $this->assertSame(array_sum(array_column($sold, 'quantity')), $c->instance()->summary['items']);
        $this->assertSame(collect($sold)->sortByDesc('quantity')->pluck('quantity')->values()->all(), array_column($sold, 'quantity'));
        $c->call('sortSold', 'name');
        $names = array_column($c->instance()->soldProducts, 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $c->assertSee('Barang terjual')->assertSee('Lihat transaksi periode ini');
        $this->assertStringContainsString('periode=kustom', $c->instance()->transactionsUrl);
        $c->call('sortSold', 'aneh')->assertSet('soldSort', 'quantity');
    }

    public function test_empty_states_and_rupiah_format(): void
    {
        \App\Models\TransactionItem::query()->delete();
        \App\Models\TransactionDiscount::query()->delete();
        Transaction::query()->delete();
        Livewire::test(Index::class)
            ->assertSeeHtml('data-empty="periode"')->assertSee('Belum ada penjualan pada periode ini.')
            ->assertSeeHtml('data-empty="grafik"')->assertSeeHtml('data-empty="hari-ini"')
            ->assertSee('Rp0')->assertDontSee('Rp 0')
            ->call('setPeriod', '7-hari')->assertSee('Lihat 30 hari');
    }
}
