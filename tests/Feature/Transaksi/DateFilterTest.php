<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Index;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
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
        Carbon::setTestNow('2026-10-14 15:00:00');
        $this->seed(ProductSeeder::class);

        // Hari ini 2 (tunai + QRIS), kemarin 1, 3 hari lalu 1, awal bulan 1, bulan lalu 1.
        foreach ([
            ['2026-10-14 08:00:00', 10000, 'tunai'], ['2026-10-14 09:00:00', 30000, 'qris'],
            ['2026-10-13 10:00:00', 20000, 'tunai'], ['2026-10-11 10:00:00', 7000, 'tunai'],
            ['2026-10-01 10:00:00', 4000, 'tunai'], ['2026-09-20 10:00:00', 9000, 'qris'],
        ] as [$at, $total, $method]) {
            Transaction::factory()->at($at)->create(['subtotal' => $total, 'total' => $total, 'paid' => $total, 'payment_method' => $method]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dates(Index $c): array
    {
        return array_values(array_unique(array_map(fn ($t) => substr($t['created_at'], 0, 10), $c->transactions->items())));
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
        $this->assertSame(['2026-10-14', '2026-10-13', '2026-10-11'], $this->dates($c));

        $c = Livewire::test(Index::class)->call('applyPreset', 'bulan-ini')->instance();
        $this->assertSame(['2026-10-14', '2026-10-13', '2026-10-11', '2026-10-01'], $this->dates($c));
    }

    public function test_custom_range_switches_to_custom_and_validates(): void
    {
        $c = Livewire::test(Index::class)
            ->set('from', '2026-10-09')
            ->set('to', '2026-10-10')
            ->assertSet('preset', 'kustom')
            ->assertHasNoErrors()
            ->assertSee('09 Okt 2026 – 10 Okt 2026');
        $this->assertSame([], $this->dates($c->instance()));

        $c->set('from', '2026-10-11')->set('to', '2026-10-13');
        $this->assertSame(['2026-10-13', '2026-10-11'], $this->dates($c->instance()));

        Livewire::test(Index::class)
            ->set('from', '2026-10-10')
            ->set('to', '2026-10-01')
            ->assertHasErrors(['to' => 'after_or_equal']);
    }

    public function test_summary_follows_filter_and_reset(): void
    {
        $c = Livewire::test(Index::class)->call('applyPreset', 'hari-ini');
        $this->assertSame(40000, $c->instance()->summary['total']);

        $c->call('clearFilter')->assertSet('preset', 'semua')->assertSet('from', '');
        $this->assertSame(6, $c->instance()->transactions->total());
    }

    public function test_query_string_restores_custom_range(): void
    {
        $this->get('/transaksi?dari=2026-10-09&sampai=2026-10-09')
            ->assertOk()
            ->assertSee('09 Oktober 2026');
    }

    public function test_summary_compares_with_previous_period_and_splits_by_method(): void
    {
        $s = Livewire::test(Index::class)->call('applyPreset', 'hari-ini')->assertSee('Per metode bayar')->instance()->summary;

        $this->assertSame(20000, $s['previous']['total']);
        $this->assertSame(100, $s['previous']['total_change']);
        $this->assertSame(75, $s['by_method']['qris']['share']);
        $this->assertSame($s['total'], $s['by_method']['tunai']['total'] + $s['by_method']['qris']['total']);
        $this->assertSame($s['count'], $s['by_method']['tunai']['count'] + $s['by_method']['qris']['count']);
    }

    public function test_no_comparison_without_date_range(): void
    {
        $this->assertNull(Livewire::test(Index::class)->instance()->summary['previous']);
    }
}
