<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Index;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_page_renders_transactions_newest_first(): void
    {
        Transaction::factory()->withItems()->at('2026-10-13 09:00:00')->create(['invoice_number' => 'INV-20261013-0001']);
        Transaction::factory()->withItems()->at('2026-10-14 09:00:00')->create(['invoice_number' => 'INV-20261014-0001']);

        $this->get('/transaksi')
            ->assertOk()
            ->assertSee('Riwayat Transaksi')
            ->assertSeeInOrder(['INV-20261014-0001', 'INV-20261013-0001']);
    }

    public function test_days_are_grouped_with_daily_totals_across_pages(): void
    {
        foreach (range(1, 55) as $i) {
            Transaction::factory()->at('2026-10-14 08:00:00')->create(['total' => 1000]);
        }
        Transaction::factory()->at('2026-10-13 08:00:00')->create(['total' => 5000]);

        $c = Livewire::test(Index::class);
        $days = $c->instance()->days;
        $this->assertCount(1, $days);
        $this->assertCount(Index::PER_PAGE, $days[0]['rows']);
        $this->assertSame(55, $days[0]['count']); // total harian dari semua transaksi hari itu
        $this->assertSame(55000, $days[0]['total']);

        $c->call('nextPage');
        $days = $c->instance()->days;
        $this->assertSame(['2026-10-14', '2026-10-13'], array_map(fn ($d) => $d['date']->toDateString(), $days));
        $this->assertSame(5000, $days[1]['total']);
        $c->assertSee('dari <strong class="text-stone-700 tabular-nums">56</strong> transaksi', false);
    }

    public function test_empty_state(): void
    {
        $this->get('/transaksi')->assertSee('Belum ada transaksi');
    }
}
