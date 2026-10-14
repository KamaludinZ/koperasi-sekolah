<?php

namespace Tests\Feature\Barang;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        Product::whereKey($this->pid('ATK002'))->update(['barcode' => '8991234567890']);
    }

    public function test_detail_and_list_include_barcode(): void
    {
        $this->getJson('/barang/'.$this->pid('ATK001'))->assertOk()
            ->assertJsonPath('data.barcode', null)->assertJsonPath('data.barcode_value', 'ATK001');
        $this->getJson('/barang/'.$this->pid('ATK002'))->assertJsonPath('data.barcode', '8991234567890')->assertJsonPath('data.barcode_value', '8991234567890');

        $row = collect($this->getJson('/barang/daftar?per_halaman=50')->assertOk()->json('data'))->firstWhere('code', 'ATK002');
        $this->assertSame('8991234567890', $row['barcode_value']);
    }

    public function test_kasir_search_finds_by_barcode(): void
    {
        $this->getJson('/kasir/barang/cari?kode=8991234567890')->assertOk()->assertJsonPath('data.code', 'ATK002');
        $this->getJson('/kasir/barang/cari?q=8991234567890')->assertOk()->assertJsonPath('data.0.code', 'ATK002');
        $this->getJson('/kasir/barang/cari?q=89912')->assertJsonPath('meta.count', 0); // barcode hanya cocok persis
    }
}
