<?php

namespace Tests\Feature\Barang;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_single_update_modes(): void
    {
        $id = $this->pid('ATK001');
        $this->putJson("/barang/{$id}/barcode", ['mode' => 'nilai', 'barcode' => '8991234567890'])->assertOk()
            ->assertJsonPath('data.barcode', '8991234567890')->assertJsonPath('data.value', '8991234567890');
        $this->putJson("/barang/{$id}/barcode", ['mode' => 'baru'])->assertOk()->assertJsonPath('data.barcode', fn ($v) => (bool) preg_match('/^2\d{12}$/', $v));
        $this->putJson("/barang/{$id}/barcode", ['mode' => 'kode'])->assertOk()->assertJsonPath('data.barcode', null)->assertJsonPath('data.value', 'ATK001');

        Product::whereKey($this->pid('ATK002'))->update(['barcode' => '8990000000001']);
        $this->putJson("/barang/{$id}/barcode", ['mode' => 'nilai', 'barcode' => '8990000000001'])->assertJsonValidationErrors('barcode');
        $this->putJson("/barang/{$id}/barcode", ['mode' => 'nilai', 'barcode' => 'atk003'])->assertJsonValidationErrors('barcode');
        $this->putJson("/barang/{$id}/barcode", ['mode' => 'nilai'])->assertJsonValidationErrors('barcode');
        $this->putJson("/barang/{$id}/barcode", ['mode' => 'acak'])->assertJsonValidationErrors('mode');
    }

    public function test_bulk_and_permissions(): void
    {
        $ids = [$this->pid('ATK001'), $this->pid('ATK002')];
        $this->postJson('/barang/barcode/massal', ['ids' => $ids, 'mode' => 'baru'])->assertOk()->assertJsonPath('data', ['changed' => 2, 'skipped' => 0]);
        $this->postJson('/barang/barcode/massal', ['ids' => $ids, 'mode' => 'baru'])->assertJsonPath('data', ['changed' => 0, 'skipped' => 2]);
        $this->postJson('/barang/barcode/massal', ['ids' => $ids, 'mode' => 'kode'])->assertJsonPath('data.changed', 2);
        $this->postJson('/barang/barcode/massal', ['ids' => [], 'mode' => 'kode'])->assertJsonValidationErrors('ids');

        $this->loginAs('kasir');
        $this->putJson('/barang/'.$ids[0].'/barcode', ['mode' => 'baru'])->assertForbidden();
        $this->postJson('/barang/barcode/massal', ['ids' => $ids, 'mode' => 'baru'])->assertForbidden();
    }

    public function test_bulk_all_without_barcode(): void
    {
        Product::whereKey($this->pid('ATK001'))->update(['barcode' => '8991234567890']);
        Product::whereKey($this->pid('ATK002'))->update(['is_active' => false]);
        $expected = Product::active()->whereNull('barcode')->count();

        $this->postJson('/barang/barcode/massal', ['semua' => true, 'mode' => 'baru'])->assertOk()
            ->assertJsonPath('data.changed', $expected)->assertJsonPath('data.remaining', 0);
        $this->assertSame('8991234567890', Product::find($this->pid('ATK001'))->barcode);
        $this->assertNull(Product::find($this->pid('ATK002'))->barcode);
        $this->postJson('/barang/barcode/massal', ['semua' => true, 'mode' => 'kode'])->assertJsonValidationErrors('mode');
    }
}
