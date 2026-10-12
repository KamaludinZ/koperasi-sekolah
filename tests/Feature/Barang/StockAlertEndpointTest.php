<?php

namespace Tests\Feature\Barang;

use App\Models\Product;
use App\Services\MinStockService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAlertEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_low_stock_endpoint(): void
    {
        $this->getJson('/barang/stok-menipis')
            ->assertOk()
            ->assertJsonPath('meta.count', 4)
            ->assertJsonPath('meta.out_of_stock', 1)
            ->assertJsonPath('data.0.code', 'ATK005')
            ->assertJsonPath('data.0.suggested', 10);
    }

    public function test_get_min_stocks_with_default(): void
    {
        $this->getJson('/barang/batas-minimum')
            ->assertOk()
            ->assertJsonCount(14, 'data')
            ->assertJsonPath('meta.default_min_stock', MinStockService::FALLBACK_DEFAULT);
    }

    public function test_update_items_category_and_default(): void
    {
        $this->putJson('/barang/batas-minimum', [
            'items' => [['product_id' => 1, 'min_stock' => 200]],
            'kategori' => 'Seragam',
            'nilai' => 1,
            'default_min_stock' => 7,
        ])->assertOk()
            ->assertJsonPath('meta.changed', 4)
            ->assertJsonPath('meta.default_min_stock', 7);

        $this->assertSame(200, Product::find(1)->min_stock);
        $this->assertSame([1, 1, 1], Product::whereIn('code', ['SRG001', 'SRG002', 'SRG003'])->pluck('min_stock')->all());

        // Batas baru langsung memengaruhi daftar stok menipis (ATK001 120 ≤ 200; topi 4 > 1).
        $codes = collect($this->getJson('/barang/stok-menipis')->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('ATK001'));
        $this->assertFalse($codes->contains('SRG002'));
    }

    public function test_update_validates(): void
    {
        $this->putJson('/barang/batas-minimum', [
            'items' => [['product_id' => 9999, 'min_stock' => -1]],
            'kategori' => 'Tidak Ada',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id', 'items.0.min_stock', 'kategori', 'nilai']);
    }
}
