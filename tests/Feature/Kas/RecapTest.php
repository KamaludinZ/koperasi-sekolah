<?php

namespace Tests\Feature\Kas;

use App\Livewire\Kas\Recap;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RecapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 16:00:00'); // Rabu, semua shift sudah ditutup
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_renders_last_seven_days(): void
    {
        $this->get('/kas/rekap')
            ->assertOk()
            ->assertSee('Rekap Kas Harian')
            ->assertSee('Rincian per hari')
            ->assertSee('Harian & shift');

        Livewire::test(Recap::class)->assertCount('rows', 7);
    }

    public function test_totals_match_daily_rows(): void
    {
        $c = Livewire::test(Recap::class)->call('setPeriod', '30-hari');
        $rows = collect(MockData::cashRecap('2026-09-15', '2026-10-14'));
        $totals = $c->get('totals');

        $this->assertCount(30, $c->get('rows'));
        $this->assertSame($rows->sum('revenue'), $totals['revenue']);
        $this->assertSame($rows->sum('difference'), $totals['difference']);
        $this->assertSame($totals['difference'], $totals['short'] + $totals['over']);
        $this->assertSame($rows->where('closed_day', false)->where('shifts', '>', 0)->count(), $totals['days']);
    }

    public function test_month_periods_and_invalid_period(): void
    {
        $c = Livewire::test(Recap::class)->call('setPeriod', 'bulan-ini');
        $this->assertSame('2026-10-01', $c->get('range')[0]->toDateString());
        $this->assertCount(14, $c->get('rows'));

        $c->call('setPeriod', 'bulan-lalu');
        $this->assertSame(['2026-09-01', '2026-09-30'], array_map(fn ($d) => $d->toDateString(), $c->get('range')));

        $c->call('setPeriod', 'setahun')->assertSet('period', 'bulan-lalu');
        $this->get('/kas/rekap?periode=ngawur')->assertOk();
    }

    public function test_filter_only_days_with_difference(): void
    {
        $c = Livewire::test(Recap::class)->call('setPeriod', '30-hari')->set('onlyDifferences', true);
        $withDiff = collect($c->get('rows'))->where('closed_day', false)->where('difference', '!=', 0);

        $c->assertSee('Total hari berselisih');
        $this->assertGreaterThan(0, $withDiff->count());
        foreach (collect($c->get('rows'))->where('closed_day', true) as $closed) {
            $c->assertDontSeeHtml('wire:key="rc-'.$closed['date'].'"');
        }
    }

    public function test_cashier_can_open_recap(): void
    {
        $this->loginAs('kasir');
        $this->get('/kas/rekap')->assertOk();
    }
}
