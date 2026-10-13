<?php

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\StockService;
use App\Services\TransactionReturnService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_relations_to_movements_sales_and_returns(): void
    {
        $this->seed(ProductSeeder::class);
        $p = Product::find($this->pid('ATK001'));
        app(StockService::class)->increase($p, 5, 'Restok');
        $tx = app(CheckoutService::class)->checkout([$p->id => 2], 'tunai', 10000);
        $r = app(TransactionReturnService::class)->requestReturn($tx, [$tx->items->first()->id => 1], 'rusak', false, 'tunai', null, null);

        $p->refresh();
        $this->assertGreaterThanOrEqual(2, $p->stockMovements()->count());
        $this->assertSame('keluar', $p->latestMovement->type);
        $this->assertSame($p->id, $p->latestMovement->product->id);
        $this->assertSame([$tx->id], $p->transactionItems()->pluck('transaction_id')->all());
        $this->assertSame([$r->id], $p->returnItems()->pluck('transaction_return_id')->all());
        $this->assertSame(0, $p->purchaseOrderItems()->count());
        $this->assertSame($p->category_id, $p->category->id);
    }
}
