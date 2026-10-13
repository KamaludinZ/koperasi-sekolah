<?php

namespace Tests\Feature\Barang;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function payload(array $override = []): array
    {
        return [
            'code' => 'mkn010', 'name' => 'Donat Gula', 'category' => 'Makanan',
            'price' => 3000, 'cost_price' => 2200, 'stock' => 12, 'min_stock' => 4,
            'unit' => 'pcs', 'is_active' => true, ...$override,
        ];
    }

    public function test_store_creates_product(): void
    {
        $this->postJson('/barang', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'MKN010')
            ->assertJsonPath('data.category', 'Makanan')
            ->assertJsonPath('data.stock', 12);

        $this->assertSame(1, Product::where('code', 'MKN010')->first()->stockMovements()->count());
    }

    public function test_store_validates(): void
    {
        $this->postJson('/barang', $this->payload(['code' => 'atk001', 'category' => 'Elektronik', 'price' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'category', 'price']);
    }

    public function test_update_product(): void
    {
        $this->putJson('/barang/'.$this->pid('MKN001'), $this->payload(['code' => 'MKN001', 'name' => 'Roti Keju', 'stock' => 30]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Roti Keju')
            ->assertJsonPath('data.stock', 30);

        $this->assertSame('penyesuaian', Product::find($this->pid('MKN001'))->stockMovements()->first()->type);
    }

    public function test_update_rejects_code_of_other_product_and_missing_product(): void
    {
        $this->putJson('/barang/'.$this->pid('MKN001'), $this->payload(['code' => 'ATK001']))
            ->assertJsonValidationErrors('code');

        $this->putJson('/barang/9999', $this->payload())->assertNotFound();
    }

    public function test_detail_and_stock_history_endpoints(): void
    {
        $p = \App\Models\Product::where('code', 'ATK001')->first();
        app(\App\Services\StockService::class)->increase($p, 3, 'Restok');
        app(\App\Services\CheckoutService::class)->checkout([$p->id => 2], 'tunai', 10000);

        $this->getJson(route('barang.show', $p))->assertOk()
            ->assertJsonPath('data.code', 'ATK001')->assertJsonPath('data.sales_30d.quantity', 2)
            ->assertJsonPath('data.movements.0.type', 'keluar')->assertJsonPath('data.movements.0.change', -2)
            ->assertJsonStructure(['data' => ['restock' => ['level', 'per_day', 'days_left', 'suggested']]]);
        $this->getJson(route('barang.movements', $p))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->getJson(route('barang.show', 999999))->assertNotFound();
    }
}
