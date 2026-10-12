<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use App\Livewire\Barang\MinStockSettings;
use App\Models\Product;
use App\Services\ProductListing;
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
            ->assertSet('minStocks.'.$this->pid('ATK001'), 20)
            ->assertSet('defaultMinStock', 5)
            ->assertSee('Atur batas minimum stok');
    }

    public function test_edit_single_and_save(): void
    {
        Livewire::test(MinStockSettings::class)
            ->call('show')
            ->set('minStocks.'.$this->pid('ATK001'), 150)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('min-stock-updated');

        $this->assertSame(150, Product::find($this->pid('ATK001'))->min_stock);
        $this->assertContains('ATK001', array_column(app(ProductListing::class)->lowStock(), 'code'));
    }

    public function test_apply_to_category(): void
    {
        Livewire::test(MinStockSettings::class)
            ->call('show')
            ->set('bulkCategory', 'Minuman')
            ->set('bulkValue', 40)
            ->call('applyBulk')
            ->assertSet('minStocks.'.$this->pid('MNM001'), 40)
            ->assertSet('minStocks.'.$this->pid('ATK001'), 20)
            ->call('save');

        $this->assertSame(40, Product::find($this->pid('MNM002'))->min_stock);
        $this->assertSame(20, Product::find($this->pid('ATK001'))->min_stock);
    }

    public function test_rejects_negative_and_saves_default_for_new_products(): void
    {
        Livewire::test(MinStockSettings::class)
            ->call('show')
            ->set('minStocks.'.$this->pid('ATK002'), -1)
            ->call('save')
            ->assertHasErrors('minStocks.'.$this->pid('ATK002'))
            ->set('minStocks.'.$this->pid('ATK002'), 10)
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
