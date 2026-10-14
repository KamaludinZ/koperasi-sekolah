<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use App\Livewire\Kasir\ProductPicker;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Services\ProductBarcode;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BarcodeFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_generate_saves_internal_ean13_and_is_scannable(): void
    {
        $id = $this->pid('ATK001');
        $c = Livewire::test(Index::class)->call('edit', $id)->call('generateBarcode');
        $barcode = $c->get('form.barcode');

        $this->assertMatchesRegularExpression('/^2\d{12}$/', $barcode);
        $this->assertSame($barcode, ProductBarcode::ean13(substr($barcode, 0, 12)));
        $c->assertSee('data-barcode="'.$barcode.'"', false)->call('save')->assertHasNoErrors();

        $this->assertSame($barcode, Product::find($id)->barcode);
        $this->assertArrayHasKey('barcode', ActivityLog::where('event', 'product.updated')->latest('id')->first()->field_changes);

        Livewire::test(ProductPicker::class)->call('scanCode', $barcode)->assertDispatched('product-picked', productId: $id);
    }

    public function test_use_code_and_own_code_store_null(): void
    {
        $id = $this->pid('ATK001');
        Product::whereKey($id)->update(['barcode' => '8991234567890']);

        Livewire::test(Index::class)->call('edit', $id)
            ->assertSet('form.barcode', '8991234567890')
            ->call('useCodeAsBarcode')->assertSet('form.barcode', '')
            ->call('save')->assertHasNoErrors();
        $this->assertNull(Product::find($id)->barcode);

        Livewire::test(Index::class)->call('edit', $id)->set('form.barcode', 'atk001')->call('save')->assertHasNoErrors();
        $this->assertNull(Product::find($id)->barcode);
    }

    public function test_barcode_must_be_unique_across_codes_and_barcodes(): void
    {
        Product::whereKey($this->pid('ATK002'))->update(['barcode' => '8990001112223']);

        Livewire::test(Index::class)->call('edit', $this->pid('ATK001'))
            ->set('form.barcode', '8990001112223')->call('save')->assertHasErrors(['form.barcode'])
            ->set('form.barcode', 'atk002')->call('save')->assertHasErrors(['form.barcode'])
            ->set('form.barcode', 'ada spasi')->call('save')->assertHasErrors(['form.barcode']);

        // Kode barang baru tidak boleh sama dengan barcode barang lain.
        Livewire::test(Index::class)->call('create')
            ->set('form.code', '8990001112223')->set('form.name', 'X')->set('form.category', 'Alat Tulis')
            ->set('form.price', 1000)->set('form.cost_price', 500)
            ->call('save')->assertHasErrors(['form.code']);
    }

    public function test_api_update_without_barcode_keeps_it(): void
    {
        $id = $this->pid('MKN001');
        Product::whereKey($id)->update(['barcode' => '8995556667778']);

        $this->putJson("/barang/{$id}", [
            'code' => 'MKN001', 'name' => 'Roti Keju', 'category' => 'Makanan', 'price' => 3000, 'cost_price' => 2200,
            'stock' => 10, 'min_stock' => 4, 'unit' => 'pcs', 'is_active' => true,
        ])->assertOk();

        $this->assertSame('8995556667778', Product::find($id)->barcode);
    }
}
