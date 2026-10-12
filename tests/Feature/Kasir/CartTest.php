<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use Livewire\Livewire;
use Tests\TestCase;

class CartTest extends TestCase
{
    public function test_picked_products_accumulate_and_total_updates(): void
    {
        $cart = Livewire::test(Cart::class)
            ->dispatch('product-picked', productId: 1)
            ->dispatch('product-picked', productId: 1)
            ->dispatch('product-picked', productId: 2)
            ->assertSet('items', [1 => 2, 2 => 1])
            ->assertSee('Buku Tulis 38 Lembar');

        $this->assertSame(11000, $cart->instance()->summary['total']);
        $this->assertSame(3, $cart->instance()->summary['count']);
    }

    public function test_quantity_is_capped_by_stock(): void
    {
        Livewire::test(Cart::class)
            ->call('setQty', 13, 10)
            ->assertSet('items', [13 => 4])
            ->assertSee('hanya tersisa 4');
    }

    public function test_decrement_to_zero_removes_line(): void
    {
        Livewire::test(Cart::class)
            ->call('add', 2)
            ->call('decrement', 2)
            ->assertSet('items', [])
            ->assertSee('Keranjang masih kosong');
    }

    public function test_clear_empties_cart(): void
    {
        Livewire::test(Cart::class)
            ->call('add', 1)
            ->call('add', 6)
            ->call('clear')
            ->assertSet('items', []);
    }
}
