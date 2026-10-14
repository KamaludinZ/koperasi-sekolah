<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Shop;
use App\Models\Member;
use App\Models\Product;
use App\Support\PortalCart;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->actingAsMember(Member::factory()->create());
    }

    public function test_catalog_search_and_category(): void
    {
        $this->get('/portal/belanja')->assertOk()->assertSee('Buku Tulis 38 Lembar')->assertSee('Rp4.000');
        Livewire::test(Shop::class)->set('search', 'pulpen')->assertSee('Pulpen Hitam')->assertDontSee('Roti Coklat')
            ->set('search', '')->call('setCategory', 'Makanan')->assertSee('Roti Coklat')->assertDontSee('Pulpen Hitam');
    }

    public function test_cart_add_update_limit_and_clear(): void
    {
        $id = $this->pid('ATK001');
        Product::whereKey($id)->update(['stock' => 2]);

        $c = Livewire::test(Shop::class)->call('add', $id)->call('add', $id)->call('add', $id)
            ->assertDispatched('toast', type: 'error', message: 'Jumlah sudah mencapai stok yang tersedia.')
            ->assertSee('data-cart-bar', false)->assertSee('Rp8.000');
        $this->assertSame([$id => 2], PortalCart::items());

        $c->call('setQty', $id, 5)->assertDispatched('toast', message: 'Stok hanya tersedia 2.');
        $c->set('cartOpen', true)->assertSee('Keranjang')->call('setQty', $id, 1);
        $this->assertSame([$id => 1], PortalCart::items());

        $c->call('setQty', $id, 0);
        $this->assertSame([], PortalCart::items());

        Product::where('code', 'ATK002')->update(['stock' => 0]);
        $c->call('add', $this->pid('ATK002'))->assertDispatched('toast', message: 'Barang ini sedang habis.');
        $c->call('add', $this->pid('ATK003'))->call('clearCart');
        $this->assertSame([], PortalCart::items());
    }

    public function test_cart_drops_inactive_products_and_is_per_member(): void
    {
        $id = $this->pid('ATK001');
        PortalCart::set($id, 2);
        Product::whereKey($id)->update(['is_active' => false]);
        $this->assertSame(0, PortalCart::summary()['count']);

        $this->actingAsMember(Member::factory()->create());
        $this->assertSame([], PortalCart::items());
    }
}
