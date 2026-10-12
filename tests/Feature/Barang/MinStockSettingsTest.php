<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use App\Livewire\Barang\MinStockSettings;
use App\Support\MockData;
use Livewire\Livewire;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinStockSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_opens_with_current_values(): void
    {
        Livewire::test(MinStockSettings::class)
            ->dispatch('open-min-stock-settings')
            ->assertSet('open', true)
            ->assertSet('minStocks.1', 20)
            ->assertSet('defaultMinStock', 5)
            ->assertSee('Atur batas minimum stok');
    }

    public function test_edit_single_and_save(): void
    {
        Livewire::test(MinStockSettings::class)
            ->call('show')
            ->set('minStocks.1', 150)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('min-stock-updated');

        $this->assertSame(150, MockData::findProduct(1)['min_stock']);
        $this->assertContains('ATK001', array_column(MockData::lowStockProducts(), 'code'));
    }

    public function test_apply_to_category(): void
    {
        Livewire::test(MinStockSettings::class)
            ->call('show')
            ->set('bulkCategory', 'Minuman')
            ->set('bulkValue', 40)
            ->call('applyBulk')
            ->assertSet('minStocks.9', 40)
            ->assertSet('minStocks.1', 20)
            ->call('save');

        $this->assertSame(40, MockData::findProduct(10)['min_stock']);
    }

    public function test_rejects_negative_and_saves_default_for_new_products(): void
    {
        Livewire::test(MinStockSettings::class)
            ->call('show')
            ->set('minStocks.2', -1)
            ->call('save')
            ->assertHasErrors('minStocks.2')
            ->set('minStocks.2', 10)
            ->set('defaultMinStock', 12)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(Index::class)->call('create')->assertSet('form.min_stock', 12);
    }

    public function test_list_refreshes_after_update(): void
    {
        Livewire::test(Index::class)
            ->dispatch('min-stock-updated', message: 'Batas minimum stok diperbarui.')
            ->assertSee('Batas minimum stok diperbarui.');
    }
}
