<?php

namespace Tests\Feature\Barang;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_list_create_update(): void
    {
        $this->getJson('/barang/kategori/data')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.name', 'Alat Tulis')->assertJsonPath('data.0.products', 5)->assertJsonPath('colors.0', 'emerald');

        $id = $this->postJson('/barang/kategori', ['name' => ' Perlengkapan  Ibadah ', 'description' => 'Peci, sajadah'])->assertCreated()
            ->assertJsonPath('data.name', 'Perlengkapan Ibadah')->assertJsonPath('data.position', 5)->json('data.id');
        $this->postJson('/barang/kategori', ['name' => 'perlengkapan ibadah'])->assertJsonValidationErrors('name');
        $this->postJson('/barang/kategori', ['name' => 'X', 'color' => 'ungu'])->assertJsonValidationErrors(['name', 'color']);

        $this->putJson("/barang/kategori/{$id}", ['name' => 'Ibadah', 'color' => 'teal'])->assertOk()->assertJsonPath('data.color', 'teal');
        $this->putJson("/barang/kategori/{$id}", ['name' => 'Alat Tulis'])->assertJsonValidationErrors('name');
    }

    public function test_delete_and_reorder(): void
    {
        $seragam = Category::where('name', 'Seragam')->first();
        $this->deleteJson("/barang/kategori/{$seragam->id}")->assertJsonValidationErrors('pindah_ke');
        $atk = Category::where('name', 'Alat Tulis')->first();
        $this->deleteJson("/barang/kategori/{$seragam->id}", ['pindah_ke' => $atk->id, 'alasan' => 'Digabung'])->assertOk()
            ->assertJsonPath('message', 'Kategori Seragam dihapus, barangnya dipindah ke Alat Tulis.');
        $this->assertSame(8, Product::where('category_id', $atk->id)->count());
        $this->assertSame('Digabung', Category::withTrashed()->find($seragam->id)->delete_reason);
        $this->deleteJson("/barang/kategori/{$atk->id}", ['pindah_ke' => $seragam->id])->assertJsonValidationErrors('pindah_ke');

        $ids = Category::ordered()->pluck('id')->reverse()->values()->all();
        $this->putJson('/barang/kategori/urutan', ['ids' => $ids])->assertOk()->assertJsonPath('data.0.name', 'Minuman');
        $this->putJson('/barang/kategori/urutan', ['ids' => [$seragam->id]])->assertJsonValidationErrors('ids.0');
    }

    public function test_permissions(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $this->getJson('/barang/kategori/data')->assertOk();
        $this->postJson('/barang/kategori', ['name' => 'Baru'])->assertForbidden();
        $this->putJson('/barang/kategori/urutan', ['ids' => [1]])->assertForbidden();

        $this->loginAs('pengurus@koperasi.test');
        $this->postJson('/barang/kategori', ['name' => 'Baru'])->assertCreated();
    }
}
