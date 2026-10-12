<?php

namespace Tests\Feature\Laporan;

use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 10:00:00');

        Transaction::factory()->withItems([$this->pid('ATK001') => 2])->at('2026-10-14 08:15:00')->create();
        Transaction::factory()->qris()->withItems([$this->pid('ATK002') => 1])->at('2026-10-14 09:40:00')->create();
        Transaction::factory()->withItems([$this->pid('ATK001') => 1])->at('2026-10-13 09:00:00')->create();
        Transaction::factory()->withItems([$this->pid('ATK001') => 5])->at('2026-10-13 13:00:00')->create();
    }

    public function test_today_summary(): void
    {
        $this->getJson('/laporan/hari-ini')
            ->assertOk()
            ->assertJsonPath('data.revenue', 11000)
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.items', 3)
            ->assertJsonPath('data.compare', 4000)
            ->assertJsonPath('data.change', 175)
            ->assertJsonPath('data.best.name', 'Buku Tulis 38 Lembar')
            ->assertJsonPath('data.hourly.8', 8000)
            ->assertJsonPath('data.top_products.1.name', 'Pulpen Hitam')
            ->assertJsonPath('meta.date', '2026-10-14');
    }

    public function test_today_without_sales(): void
    {
        $this->travelTo('2026-10-20 07:00:00');

        $this->getJson('/laporan/hari-ini')
            ->assertJsonPath('data.revenue', 0)
            ->assertJsonPath('data.change', null)
            ->assertJsonPath('data.best', null)
            ->assertJsonPath('data.last_at', null)
            ->assertJsonPath('data.top_products', []);
    }

    public function test_period_summary_and_chart(): void
    {
        $this->getJson('/laporan/ringkasan?periode=7-hari')
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', 35000)
            ->assertJsonPath('data.summary.gross_profit', 7000)
            ->assertJsonPath('data.comparison.label', '7 hari sebelumnya')
            ->assertJsonPath('data.top_products.0.quantity', 8)
            ->assertJsonPath('meta.from', '2026-10-08 00:00:00');

        $this->getJson('/laporan/grafik?rentang=30-hari')
            ->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta.unit', 'hari');

        $this->getJson('/laporan/ringkasan?periode=tahun-ini')->assertUnprocessable();
        $this->getJson('/laporan/grafik?rentang=1-tahun')->assertUnprocessable();
    }
}
