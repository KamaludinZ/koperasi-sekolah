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
        $this->putJson('/barang/6', $this->payload(['code' => 'MKN001', 'name' => 'Roti Keju', 'stock' => 30]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Roti Keju')
            ->assertJsonPath('data.stock', 30);

        $this->assertSame('penyesuaian', Product::find(6)->stockMovements()->first()->type);
    }

    public function test_update_rejects_code_of_other_product_and_missing_product(): void
    {
        $this->putJson('/barang/6', $this->payload(['code' => 'ATK001']))
            ->assertJsonValidationErrors('code');

        $this->putJson('/barang/9999', $this->payload())->assertNotFound();
    }
}
