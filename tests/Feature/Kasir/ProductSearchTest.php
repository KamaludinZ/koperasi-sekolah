<?php

namespace Tests\Feature\Kasir;

use App\Models\Product;
use App\Services\ProductCatalog;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_search_by_name_is_case_insensitive(): void
    {
        $this->getJson('/kasir/barang/cari?q=PULPEN')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.code', 'ATK002')
            ->assertJsonPath('data.0.price', 3000)
            ->assertJsonPath('data.0.category', 'Alat Tulis');
    }

    public function test_search_by_code_and_category(): void
    {
        $this->getJson('/kasir/barang/cari?q=mnm')->assertJsonPath('meta.count', 3);
        $this->getJson('/kasir/barang/cari?kategori=Seragam')->assertJsonPath('meta.count', 3);
        $this->getJson('/kasir/barang/cari?q=topi&kategori=Makanan')->assertJsonPath('meta.count', 0);
    }

    public function test_exact_code_lookup(): void
    {
        $this->getJson('/kasir/barang/cari?kode=srg001')
            ->assertOk()
            ->assertJsonPath('data.name', 'Dasi Sekolah');

        $this->getJson('/kasir/barang/cari?kode=TIDAKADA')->assertNotFound();
    }

    public function test_inactive_products_are_hidden(): void
    {
        Product::where('code', 'ATK002')->update(['is_active' => false]);

        $this->getJson('/kasir/barang/cari?q=pulpen')->assertJsonPath('meta.count', 0);
        $this->getJson('/kasir/barang/cari?kode=ATK002')->assertNotFound();
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $this->getJson('/kasir/barang/cari?q=%25')->assertJsonPath('meta.count', 0);
        $this->getJson('/kasir/barang/cari?q=_')->assertJsonPath('meta.count', 0);
    }

    public function test_exact_code_match_is_ranked_first(): void
    {
        Product::create(['code' => 'X-ATK001', 'name' => 'Aaa Sampul ATK001', 'price' => 1000]);

        $results = app(ProductCatalog::class)->search('atk001');

        $this->assertSame('ATK001', $results[0]['code']);
    }

    public function test_validates_input(): void
    {
        $this->getJson('/kasir/barang/cari?limit=999')->assertUnprocessable();
    }
}
