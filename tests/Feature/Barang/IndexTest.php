<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use Livewire\Livewire;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_page_lists_products_with_stock(): void
    {
        $this->get('/barang')
            ->assertOk()
            ->assertSee('Barang & Stok')
            ->assertSee('Buku Tulis 38 Lembar')
            ->assertSee('Habis');
    }

    public function test_search_and_category_filter(): void
    {
        Livewire::test(Index::class)
            ->set('search', 'teh')
            ->assertSee('Teh Kotak')
            ->assertDontSee('Pulpen Hitam')
            ->set('search', '')
            ->set('category', 'Seragam')
            ->assertSee('Dasi Sekolah')
            ->assertDontSee('Roti Coklat');
    }

    public function test_sort_by_lowest_stock(): void
    {
        $products = Livewire::test(Index::class)
            ->set('sort', 'stock')
            ->instance()->paginated->items();

        $this->assertSame('ATK005', $products[0]['code']);
    }

    public function test_stats_count_low_and_out_of_stock(): void
    {
        $stats = Livewire::test(Index::class)->instance()->stats;

        $this->assertSame(14, $stats['total']);
        $this->assertSame(1, $stats['out']);
        $this->assertSame(3, $stats['low']);
    }

    public function test_status_filter_shows_out_of_stock_and_inactive(): void
    {
        \App\Models\Product::where('code', 'ATK002')->update(['is_active' => false]);

        $page = Livewire::test(Index::class)->call('toggleStatus', 'habis');
        $this->assertSame(['ATK005'], array_column($page->instance()->paginated->items(), 'code'));

        $page->call('toggleStatus', 'nonaktif')->assertSee('Nonaktif · tidak dijual');
        $this->assertSame(['ATK002'], array_column($page->instance()->paginated->items(), 'code'));

        $page->call('toggleStatus', 'nonaktif')->assertSet('status', '');
    }

    public function test_stats_count_inactive(): void
    {
        \App\Models\Product::where('code', 'ATK003')->update(['is_active' => false]);

        $this->assertSame(1, Livewire::test(Index::class)->instance()->stats['inactive']);
    }
}
