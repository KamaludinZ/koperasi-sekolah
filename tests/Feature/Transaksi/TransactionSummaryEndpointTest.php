<?php

namespace Tests\Feature\Transaksi;

use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionSummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');

        // Hari ini: 2 tunai + 1 QRIS. Kemarin: 1 tunai. Awal bulan: 1 tunai.
        $this->tx('2026-10-14 08:00:00', 10000, 2);
        $this->tx('2026-10-14 09:00:00', 20000, 3);
        $this->tx('2026-10-14 10:00:00', 30000, 1, 'qris');
        $this->tx('2026-10-13 23:59:59', 40000, 4);
        $this->tx('2026-10-01 07:00:00', 5000, 1);
    }

    private function tx(string $at, int $total, int $items, string $method = 'tunai'): void
    {
        Transaction::factory()->at($at)->create([
            'subtotal' => $total, 'total' => $total, 'paid' => $total,
            'item_count' => $items, 'payment_method' => $method,
        ]);
    }

    public function test_today_summary_compares_with_yesterday(): void
    {
        $this->getJson('/transaksi/ringkasan?periode=hari-ini')
            ->assertOk()
            ->assertJsonPath('meta.from', '2026-10-14')
            ->assertJsonPath('meta.label', '14 Oktober 2026')
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.total', 60000)
            ->assertJsonPath('data.items', 6)
            ->assertJsonPath('data.average', 20000)
            ->assertJsonPath('data.by_method.tunai.count', 2)
            ->assertJsonPath('data.by_method.qris.total', 30000)
            ->assertJsonPath('data.by_method.qris.share', 50)
            ->assertJsonPath('data.previous.count', 1)
            ->assertJsonPath('data.previous.total', 40000)
            ->assertJsonPath('data.previous.total_change', 50)
            ->assertJsonPath('data.previous.count_change', 200);
    }

    public function test_custom_range_overrides_preset_and_all_has_no_comparison(): void
    {
        $this->getJson('/transaksi/ringkasan?periode=hari-ini&dari=2026-10-01&sampai=2026-10-13')
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.total', 45000);

        $this->getJson('/transaksi/ringkasan')
            ->assertJsonPath('data.count', 5)
            ->assertJsonPath('data.previous', null)
            ->assertJsonPath('meta.label', null);

        $this->getJson('/transaksi/ringkasan?periode=bulan-ini&metode=tunai')->assertJsonPath('data.count', 4);
    }

    public function test_empty_previous_period_has_null_change(): void
    {
        $this->getJson('/transaksi/ringkasan?dari=2026-10-01&sampai=2026-10-01')
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.previous.count', 0)
            ->assertJsonPath('data.previous.total_change', null);
    }

    public function test_list_accepts_preset_filter(): void
    {
        $this->getJson('/transaksi/daftar?periode=kemarin')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.from', '2026-10-13')
            ->assertJsonPath('data.0.total', 40000);
        $this->getJson('/transaksi/daftar?periode=7-hari')->assertJsonPath('meta.total', 4);
    }

    public function test_validates_preset(): void
    {
        $this->getJson('/transaksi/ringkasan?periode=tahun-lalu')->assertUnprocessable();
        $this->getJson('/transaksi/ringkasan?dari=2026-10-14&sampai=2026-10-01')->assertJsonValidationErrors('sampai');
    }
}
