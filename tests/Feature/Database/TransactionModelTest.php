<?php

namespace Tests\Feature\Database;

use App\Models\Transaction;
use App\Services\CheckoutService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransactionModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_checkout_stores_item_count(): void
    {
        $tx = app(CheckoutService::class)->checkout([$this->pid('ATK001') => 2, $this->pid('MNM001') => 3], 'qris', null);

        $this->assertSame(5, $tx->fresh()->item_count);
    }

    public function test_factory_builds_consistent_totals(): void
    {
        $tx = Transaction::factory()->withItems([$this->pid('ATK001') => 2, $this->pid('MKN001') => 1])->create();

        $this->assertSame('13000.00', $tx->total);
        $this->assertSame('15000.00', $tx->paid);
        $this->assertSame('2000.00', $tx->change);
        $this->assertSame(3, $tx->item_count);
    }

    public function test_scopes(): void
    {
        $old = Transaction::factory()->at('2026-10-01 09:00:00')->withItems()->create();
        $mid = Transaction::factory()->qris()->at('2026-10-05 10:00:00')->withItems()->create();
        $new = Transaction::factory()->at('2026-10-09 11:00:00')->withItems()->create();

        $this->assertSame([$new->id, $mid->id, $old->id], Transaction::latestFirst()->pluck('id')->all());
        $this->assertSame([$mid->id], Transaction::betweenDates(Carbon::parse('2026-10-02'), Carbon::parse('2026-10-06'))->pluck('id')->all());
        $this->assertSame([$mid->id], Transaction::paymentMethod('qris')->pluck('id')->all());
        $this->assertSame(3, Transaction::paymentMethod(null)->count());
    }

    public function test_demo_seeder_creates_history_without_touching_stock(): void
    {
        $stock = \App\Models\Product::pluck('stock', 'id');

        (new TransactionSeeder)->run(14);

        $this->assertGreaterThan(10, Transaction::count());
        $this->assertSame(0, Transaction::where('change', '<', 0)->count());
        $this->assertEquals($stock, \App\Models\Product::pluck('stock', 'id'));
        $tx = Transaction::with('items')->first();
        $this->assertSame((int) $tx->items->sum('quantity'), $tx->item_count);
    }
}
