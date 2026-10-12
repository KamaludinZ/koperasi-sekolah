<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Index;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_transactions_newest_first(): void
    {
        $transactions = MockData::transactions();
        $this->assertNotEmpty($transactions);
        $this->assertGreaterThanOrEqual($transactions[1]['created_at'], $transactions[0]['created_at']);

        $this->get('/transaksi')
            ->assertOk()
            ->assertSee('Riwayat Transaksi')
            ->assertSee($transactions[0]['invoice_number']);
    }

    public function test_days_are_grouped_with_daily_totals(): void
    {
        $component = Livewire::test(Index::class)->instance();
        $days = $component->days;

        $this->assertSame(
            array_sum(array_column($days, 'total')),
            $component->summary['total'],
        );
        $this->assertTrue($days[0]['date']->greaterThan($days[1]['date']));
    }

    public function test_mock_data_is_deterministic(): void
    {
        $this->assertSame(MockData::transactions(), MockData::transactions());
    }
}
