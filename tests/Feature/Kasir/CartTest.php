<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use Livewire\Livewire;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_picked_products_accumulate_and_total_updates(): void
    {
        $cart = Livewire::test(Cart::class)
            ->dispatch('product-picked', productId: $this->pid('ATK001'))
            ->dispatch('product-picked', productId: $this->pid('ATK001'))
            ->dispatch('product-picked', productId: $this->pid('ATK002'))
            ->assertSet('items', [$this->pid('ATK001') => 2, $this->pid('ATK002') => 1])
            ->assertSee('Buku Tulis 38 Lembar');

        $this->assertSame(11000, $cart->instance()->summary['total']);
        $this->assertSame(3, $cart->instance()->summary['count']);
    }

    public function test_quantity_is_capped_by_stock(): void
    {
        Livewire::test(Cart::class)
            ->call('setQty', $this->pid('SRG002'), 10)
            ->assertSet('items', [$this->pid('SRG002') => 4])
            ->assertSee('hanya tersisa 4');
    }

    public function test_decrement_to_zero_removes_line(): void
    {
        Livewire::test(Cart::class)
            ->call('add', $this->pid('ATK002'))
            ->call('decrement', $this->pid('ATK002'))
            ->assertSet('items', [])
            ->assertSee('Keranjang masih kosong');
    }

    public function test_clear_empties_cart(): void
    {
        Livewire::test(Cart::class)
            ->call('add', $this->pid('ATK001'))
            ->call('add', $this->pid('MKN001'))
            ->call('clear')
            ->assertSet('items', []);
    }

    public function test_increment_and_direct_quantity_change(): void
    {
        Livewire::test(Cart::class)
            ->call('add', $this->pid('ATK002'))
            ->call('increment', $this->pid('ATK002'))
            ->assertSet('items', [$this->pid('ATK002') => 2])
            ->call('setQty', $this->pid('ATK002'), 7)
            ->assertSet('items', [$this->pid('ATK002') => 7])
            ->call('setQty', $this->pid('ATK002'), 0)
            ->assertSet('items', []);
    }

    public function test_remove_can_be_undone(): void
    {
        Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK001'), 3)
            ->call('remove', $this->pid('ATK001'))
            ->assertSet('items', [])
            ->assertSee('Urungkan')
            ->call('undoRemove')
            ->assertSet('items', [$this->pid('ATK001') => 3])
            ->assertSet('lastRemoved', null);
    }

    public function test_line_shows_stock_notes_and_disables_plus_at_max(): void
    {
        \App\Models\Product::where('code', 'ATK001')->update(['stock' => 3, 'min_stock' => 2]);
        $c = \Livewire\Livewire::test(\App\Livewire\Kasir\Cart::class)->call('add', $this->pid('ATK001'));
        $c->assertSee('Sisa 2 pcs — stok menipis');
        $c->call('increment', $this->pid('ATK001'))->call('increment', $this->pid('ATK001'))
            ->assertSee('Stok habis setelah ini (3 pcs)')->assertSeeHtml('data-stock-note="max"');
    }
}
