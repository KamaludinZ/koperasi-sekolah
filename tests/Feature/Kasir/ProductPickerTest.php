<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\ProductPicker;
use Livewire\Livewire;
use Tests\TestCase;

class ProductPickerTest extends TestCase
{
    public function test_filters_by_name_or_code(): void
    {
        Livewire::test(ProductPicker::class)
            ->set('search', 'pulpen')
            ->assertSee('Pulpen Hitam')
            ->assertDontSee('Roti Coklat')
            ->set('search', 'mkn001')
            ->assertSee('Roti Coklat')
            ->assertDontSee('Pulpen Hitam');
    }

    public function test_filters_by_category(): void
    {
        Livewire::test(ProductPicker::class)
            ->call('setCategory', 'Minuman')
            ->assertSee('Teh Kotak')
            ->assertDontSee('Dasi Sekolah');
    }

    public function test_picking_dispatches_event(): void
    {
        Livewire::test(ProductPicker::class)
            ->call('pick', 2)
            ->assertDispatched('product-picked', productId: 2);
    }

    public function test_out_of_stock_cannot_be_picked(): void
    {
        Livewire::test(ProductPicker::class)
            ->call('pick', 5)
            ->assertNotDispatched('product-picked');
    }

    public function test_enter_picks_exact_code_and_clears_search(): void
    {
        Livewire::test(ProductPicker::class)
            ->set('search', 'ATK001')
            ->call('pickFromSearch')
            ->assertDispatched('product-picked', productId: 1)
            ->assertSet('search', '');
    }
}
