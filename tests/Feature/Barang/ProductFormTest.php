<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use App\Models\Product;
use Livewire\Livewire;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_can_add_new_product(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->assertSet('editing', true)
            ->assertSee('Tambah barang')
            ->set('form.code', 'atk010')
            ->set('form.name', 'Buku Gambar A4')
            ->set('form.category', 'Alat Tulis')
            ->set('form.price', 7000)
            ->set('form.cost_price', 5500)
            ->set('form.stock', 30)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertSee('Buku Gambar A4 ditambahkan.');

        $product = Product::where('code', 'ATK010')->firstOrFail();
        $this->assertSame('7000.00', $product->price);
        $this->assertSame(30, $product->stock);
        $this->assertSame('Alat Tulis', $product->category->name);
        $this->assertSame(['masuk', 30, 'Stok awal'], [$product->stockMovements->first()->type, $product->stockMovements->first()->quantity, $product->stockMovements->first()->note]);
    }

    public function test_can_edit_existing_product(): void
    {
        Livewire::test(Index::class)
            ->call('edit', 2)
            ->assertSet('form.name', 'Pulpen Hitam')
            ->set('form.price', 3500)
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::find(2);
        $this->assertSame('3500.00', $product->price);
        $this->assertFalse($product->is_active);
        $this->assertSame(0, $product->stockMovements()->count()); // stok tidak berubah → tanpa riwayat
    }

    public function test_validation_rejects_duplicate_code_and_missing_fields(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('form.code', 'atk001')
            ->call('save')
            ->assertHasErrors(['form.code', 'form.name', 'form.category', 'form.price', 'form.cost_price'])
            ->assertSet('editing', true);
    }

    public function test_editing_keeps_its_own_code_valid(): void
    {
        Livewire::test(Index::class)
            ->call('edit', 1)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_stock_change_from_form_is_recorded_as_adjustment(): void
    {
        Livewire::test(Index::class)
            ->call('edit', 1)
            ->set('form.stock', 100)
            ->call('save')
            ->assertHasNoErrors();

        $movement = Product::find(1)->stockMovements()->first();
        $this->assertSame(['penyesuaian', 20, 120, 100], [$movement->type, $movement->quantity, $movement->stock_before, $movement->stock_after]);
    }

    public function test_saved_product_appears_in_list(): void
    {
        Livewire::test(Index::class)
            ->call('edit', 2)
            ->set('form.name', 'Pulpen Biru')
            ->call('save')
            ->assertSee('Pulpen Biru');
    }
}
