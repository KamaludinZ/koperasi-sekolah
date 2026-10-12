<?php

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_product_with_stock_records_initial_stock(): void
    {
        $product = Product::factory()->create(['stock' => 25]);

        $m = $product->stockMovements()->sole();
        $this->assertSame([StockMovement::TYPE_IN, 25, 0, 25, 'Stok awal'], [$m->type, $m->quantity, $m->stock_before, $m->stock_after, $m->note]);
    }

    public function test_creating_without_stock_records_nothing(): void
    {
        Product::factory()->create(['stock' => 0]);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_direct_model_update_is_recorded(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $product->update(['stock' => 4]);
        $product->update(['stock' => 9]);
        $product->update(['name' => 'Nama lain']); // bukan perubahan stok

        $history = $product->stockMovements()->get();
        $this->assertCount(3, $history);
        $this->assertSame([StockMovement::TYPE_IN, 5, 4, 9], [$history[0]->type, $history[0]->quantity, $history[0]->stock_before, $history[0]->stock_after]);
        $this->assertSame([StockMovement::TYPE_OUT, 6, 10, 4], [$history[1]->type, $history[1]->quantity, $history[1]->stock_before, $history[1]->stock_after]);
    }

    public function test_stock_service_changes_are_not_recorded_twice(): void
    {
        $product = Product::factory()->create(['stock' => 10]); // +1 riwayat "Stok awal"

        app(StockService::class)->decrease($product, 3, 'Rusak');
        app(StockService::class)->increase($product, 5);

        $this->assertSame(3, $product->stockMovements()->count());
        $this->assertSame(12, $product->fresh()->stock);
    }

    public function test_history_records_logged_in_user(): void
    {
        $user = \App\Models\User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);

        $this->actingAs($user);
        $product->update(['stock' => 3]);

        $this->assertSame($user->id, $product->stockMovements()->first()->user_id);
    }
}
