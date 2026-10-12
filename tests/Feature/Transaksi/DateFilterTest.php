<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Index;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Senin siang: data tiruan punya transaksi hari ini & kemarin (Minggu dilewati).
        Carbon::setTestNow('2026-10-14 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dates(Index $c): array
    {
        return array_values(array_unique(array_map(fn ($t) => substr($t['created_at'], 0, 10), $c->transactions)));
    }

    public function test_today_and_yesterday_presets(): void
    {
        $c = Livewire::test(Index::class)->call('applyPreset', 'hari-ini');
        $this->assertSame(['2026-10-14'], $this->dates($c->instance()));

        $c->call('applyPreset', 'kemarin');
        $this->assertSame(['2026-10-13'], $this->dates($c->instance()));
    }

    public function test_seven_days_and_month(): void
    {
        $c = Livewire::test(Index::class)->call('applyPreset', '7-hari')->instance();
        foreach ($this->dates($c) as $d) {
            $this->assertGreaterThanOrEqual('2026-10-08', $d);
        }
        $this->assertLessThan(count(MockData::transactions()), count($c->transactions));

        $c = Livewire::test(Index::class)->call('applyPreset', 'bulan-ini')->instance();
        foreach ($this->dates($c) as $d) {
            $this->assertStringStartsWith('2026-10-', $d);
        }
    }

    public function test_custom_range_switches_to_custom_and_validates(): void
    {
        $c = Livewire::test(Index::class)
            ->set('from', '2026-10-09')
            ->set('to', '2026-10-10')
            ->assertSet('preset', 'kustom')
            ->assertHasNoErrors()
            ->assertSee('09 Okt 2026 – 10 Okt 2026');
        $this->assertSame(['2026-10-10', '2026-10-09'], $this->dates($c->instance()));

        Livewire::test(Index::class)
            ->set('from', '2026-10-10')
            ->set('to', '2026-10-01')
            ->assertHasErrors(['to' => 'after_or_equal']);
    }

    public function test_summary_follows_filter_and_reset(): void
    {
        $c = Livewire::test(Index::class)->call('applyPreset', 'hari-ini');
        $today = collect(MockData::transactions())->filter(fn ($t) => str_starts_with($t['created_at'], '2026-10-14'));
        $this->assertSame($today->sum('total'), $c->instance()->summary['total']);

        $c->call('clearFilter')->assertSet('preset', 'semua')->assertSet('from', '');
        $this->assertCount(count(MockData::transactions()), $c->instance()->transactions);
    }

    public function test_query_string_restores_custom_range(): void
    {
        $this->get('/transaksi?dari=2026-10-09&sampai=2026-10-09')
            ->assertOk()
            ->assertSee('09 Oktober 2026');
    }

    public function test_summary_compares_with_previous_period_and_splits_by_method(): void
    {
        $all = collect(MockData::transactions());
        $today = $all->filter(fn ($t) => str_starts_with($t['created_at'], '2026-10-14'));
        $yesterday = $all->filter(fn ($t) => str_starts_with($t['created_at'], '2026-10-13'));

        $s = Livewire::test(Index::class)->call('applyPreset', 'hari-ini')->assertSee('Per metode bayar')->instance()->summary;

        $this->assertSame($yesterday->sum('total'), $s['previous']['total']);
        $this->assertSame(
            (int) round(($today->sum('total') - $yesterday->sum('total')) / $yesterday->sum('total') * 100),
            $s['previous']['total_change'],
        );
        $this->assertSame($s['total'], $s['by_method']['tunai']['total'] + $s['by_method']['qris']['total']);
        $this->assertSame($s['count'], $s['by_method']['tunai']['count'] + $s['by_method']['qris']['count']);
    }

    public function test_no_comparison_without_date_range(): void
    {
        $this->assertNull(Livewire::test(Index::class)->instance()->summary['previous']);
    }
}
