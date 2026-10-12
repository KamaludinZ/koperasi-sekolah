<?php

namespace Tests\Feature\Barang;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductListEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_paginates_with_meta_and_stats(): void
    {
        $this->getJson('/barang/daftar')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 14)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.stats.out', 1)
            ->assertJsonPath('meta.stats.low', 3)
            ->assertJsonPath('data.0.name', 'Air Mineral 600 ml');

        $this->getJson('/barang/daftar?page=2')->assertJsonCount(4, 'data');
    }

    public function test_filters_and_sorting(): void
    {
        $this->getJson('/barang/daftar?q=teh')->assertJsonPath('meta.total', 1);
        $this->getJson('/barang/daftar?kategori=Minuman&urut=price_desc')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.code', 'MNM003');
        $this->getJson('/barang/daftar?status=habis')->assertJsonPath('data.0.code', 'ATK005');
        $this->getJson('/barang/daftar?status=menipis')->assertJsonPath('meta.total', 3);

        Product::where('code', 'ATK001')->update(['is_active' => false]);
        $this->getJson('/barang/daftar?status=nonaktif')->assertJsonPath('data.0.code', 'ATK001');
    }

    public function test_out_of_range_page_falls_back_to_last_page(): void
    {
        $this->getJson('/barang/daftar?page=9')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(4, 'data');
    }

    public function test_validates_query(): void
    {
        $this->getJson('/barang/daftar?status=aneh')->assertUnprocessable();
        $this->getJson('/barang/daftar?per_halaman=7')->assertUnprocessable();
    }
}
