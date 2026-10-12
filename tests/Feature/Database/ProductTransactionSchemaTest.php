<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductTransactionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_have_prd_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('products', ['code', 'name', 'category_id', 'price', 'cost_price', 'stock', 'min_stock', 'unit', 'is_active']));
        $this->assertTrue(Schema::hasColumns('stock_movements', ['product_id', 'type', 'quantity', 'note', 'user_id', 'created_at']));
        $this->assertTrue(Schema::hasColumns('transactions', ['invoice_number', 'user_id', 'member_id', 'subtotal', 'tax', 'discount', 'total', 'paid', 'change', 'payment_method']));
        $this->assertTrue(Schema::hasColumns('transaction_items', ['transaction_id', 'product_id', 'product_name', 'price', 'quantity', 'subtotal']));
    }

    public function test_transaction_with_items_and_relations(): void
    {
        $category = Category::create(['name' => 'Alat Tulis']);
        $product = $category->products()->create(['code' => 'ATK001', 'name' => 'Buku Tulis', 'price' => 4000, 'stock' => 10, 'min_stock' => 12]);

        $tx = Transaction::create(['invoice_number' => 'INV-1', 'subtotal' => 8000, 'total' => 8000, 'paid' => 10000, 'change' => 2000]);
        $tx->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'price' => 4000, 'quantity' => 2, 'subtotal' => 8000]);

        $this->assertSame('8000.00', $tx->fresh()->items->first()->subtotal);
        $this->assertSame('Alat Tulis', $product->category->name);
        $this->assertSame(1, Product::lowStock()->count());
    }

    public function test_product_code_is_unique(): void
    {
        Product::create(['code' => 'X1', 'name' => 'A', 'price' => 1]);

        $this->expectException(QueryException::class);
        Product::create(['code' => 'X1', 'name' => 'B', 'price' => 1]);
    }

    public function test_deleting_product_keeps_transaction_item_snapshot(): void
    {
        $product = Product::create(['code' => 'X1', 'name' => 'Roti', 'price' => 5000]);
        $tx = Transaction::create(['invoice_number' => 'INV-2', 'subtotal' => 5000, 'total' => 5000, 'paid' => 5000]);
        $item = $tx->items()->create(['product_id' => $product->id, 'product_name' => 'Roti', 'price' => 5000, 'quantity' => 1, 'subtotal' => 5000]);

        $product->delete();

        $this->assertNull($item->fresh()->product_id);
        $this->assertSame('Roti', $item->fresh()->product_name);
    }

    public function test_settings_store_values(): void
    {
        \App\Models\Setting::set(\App\Models\Setting::DEFAULT_MIN_STOCK, 8);

        $this->assertSame(8, \App\Models\Setting::get(\App\Models\Setting::DEFAULT_MIN_STOCK));
        $this->assertSame('x', \App\Models\Setting::get('tidak.ada', 'x'));
    }

    public function test_product_factory_states(): void
    {
        Product::factory()->count(3)->create();
        Product::factory()->lowStock()->create();
        Product::factory()->inactive()->create();

        $this->assertSame(4, Product::active()->count());
        $this->assertGreaterThanOrEqual(1, Product::lowStock()->count());
    }
}
