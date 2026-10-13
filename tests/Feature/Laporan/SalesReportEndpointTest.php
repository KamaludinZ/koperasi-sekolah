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

        $this->getJson('/laporan/ringkasan?dari=2026-10-13&sampai=2026-10-13')
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', 24000)
            ->assertJsonPath('data.summary.count', 2)
            ->assertJsonPath('data.comparison.label', '1 hari sebelumnya')
            ->assertJsonPath('data.top_products.0.quantity', 6)
            ->assertJsonPath('meta.period', 'kustom')
            ->assertJsonPath('meta.from', '2026-10-13 00:00:00')
            ->assertJsonPath('meta.to', '2026-10-13 23:59:59');
        $this->getJson('/laporan/ringkasan?dari=2026-10-14&sampai=2026-10-13')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/ringkasan?dari=2026-10-14&sampai=2026-10-20')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/ringkasan?dari=2026-10-01')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/ringkasan?dari=2025-01-01&sampai=2026-10-14')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/ringkasan?periode=tahun-ini')->assertUnprocessable();
        $this->getJson('/laporan/grafik?rentang=1-tahun')->assertUnprocessable();
    }

    public function test_chart_quick_ranges_include_totals_and_peak(): void
    {
        $this->getJson('/laporan/grafik')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('meta.range', '7-hari')
            ->assertJsonPath('meta.from', '2026-10-08')
            ->assertJsonPath('meta.total', 35000)
            ->assertJsonPath('meta.count', 4)
            ->assertJsonPath('meta.peak.date', '2026-10-13')
            ->assertJsonPath('meta.peak.value', 24000)
            ->assertJsonPath('data.6.label', 'Rab 14');

        $this->getJson('/laporan/grafik?rentang=12-minggu')
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.unit', 'minggu')
            ->assertJsonPath('data.11.date', '2026-10-12')
            ->assertJsonPath('data.11.end', '2026-10-18');
    }

    public function test_chart_custom_range_with_auto_and_chosen_unit(): void
    {
        Transaction::factory()->withItems([$this->pid('ATK002') => 3])->at('2026-08-20 09:00:00')->create();

        $this->getJson('/laporan/grafik?dari=2026-10-12&sampai=2026-10-14')
            ->assertJsonPath('meta.range', 'kustom')
            ->assertJsonPath('meta.unit', 'hari')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.value', 24000);

        // 2 bulan lebih → otomatis per minggu; minggu pertama parsial dimulai Senin.
        $this->getJson('/laporan/grafik?dari=2026-08-01&sampai=2026-10-14')
            ->assertJsonPath('meta.unit', 'minggu')
            ->assertJsonPath('data.0.date', '2026-07-27')
            ->assertJsonPath('meta.total', 44000);

        $this->getJson('/laporan/grafik?dari=2026-08-01&sampai=2026-10-14&satuan=bulan')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.label', 'Agt 2026')
            ->assertJsonPath('data.0.value', 9000)
            ->assertJsonPath('data.2.value', 35000);

        // Hari kosong tetap muncul dengan nilai 0.
        $this->getJson('/laporan/grafik?dari=2026-10-01&sampai=2026-10-02')
            ->assertJsonPath('data.0.value', 0)
            ->assertJsonPath('meta.peak', null);
    }

    public function test_chart_validation(): void
    {
        $this->getJson('/laporan/grafik?dari=2026-10-01')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/grafik?dari=2026-10-10&sampai=2026-10-01')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/grafik?dari=2026-10-20&sampai=2026-10-21')->assertJsonValidationErrors('dari');
        $this->getJson('/laporan/grafik?dari=2024-01-01&sampai=2026-10-14&satuan=hari')->assertJsonValidationErrors('satuan');
        $this->getJson('/laporan/grafik?dari=2026-10-01&sampai=2026-10-14&satuan=tahun')->assertJsonValidationErrors('satuan');
    }
}
