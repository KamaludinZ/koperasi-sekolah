<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Index;
use App\Support\MockData;
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

        $product = collect(MockData::products())->firstWhere('code', 'ATK010');
        $this->assertSame(15, $product['id']);
        $this->assertSame(7000, $product['price']);
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

        $product = MockData::findProduct(2);
        $this->assertSame(3500, $product['price']);
        $this->assertFalse($product['is_active']);
        $this->assertNull(collect(MockData::activeProducts())->firstWhere('id', 2));
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
}
