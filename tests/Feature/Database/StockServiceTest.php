<?php

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->product = Product::factory()->create(['name' => 'Pensil', 'stock' => 10]);
    }

    public function test_increase_records_movement_with_snapshot(): void
    {
        $m = $this->stock->increase($this->product, 5, 'Restok');

        $this->assertSame(15, $this->product->stock);
        $this->assertSame(15, $this->product->fresh()->stock);
        $this->assertSame([StockMovement::TYPE_IN, 5, 10, 15, 'Restok'], [$m->type, $m->quantity, $m->stock_before, $m->stock_after, $m->note]);
        $this->assertSame(5, $m->signed_quantity);
        $this->assertSame('Stok masuk', $m->type_label);
    }

    public function test_decrease_and_insufficient_stock(): void
    {
        $m = $this->stock->decrease($this->product, 4);
        $this->assertSame(-4, $m->signed_quantity);
        $this->assertSame(6, $this->product->fresh()->stock);

        try {
            $this->stock->decrease($this->product, 7);
            $this->fail('Seharusnya stok kurang.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stok Pensil tinggal 6', $e->errors()['stock'][0]);
        }

        $this->assertSame(6, $this->product->fresh()->stock);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_adjust_to_physical_count(): void
    {
        $m = $this->stock->adjustTo($this->product, 7, 'Stock opname');

        $this->assertSame(StockMovement::TYPE_ADJUSTMENT, $m->type);
        $this->assertSame(3, $m->quantity);
        $this->assertSame(-3, $m->signed_quantity);
        $this->assertSame(7, $this->product->fresh()->stock);
        $this->assertNull($this->stock->adjustTo($this->product, 7));
    }

    public function test_rejects_invalid_quantities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->stock->increase($this->product, 0);
    }

    public function test_history_is_latest_first(): void
    {
        $this->stock->increase($this->product, 1, 'pertama');
        $this->stock->increase($this->product, 1, 'kedua');

        $this->assertSame(['kedua', 'pertama'], $this->product->stockMovements()->pluck('note')->all());
    }
}
