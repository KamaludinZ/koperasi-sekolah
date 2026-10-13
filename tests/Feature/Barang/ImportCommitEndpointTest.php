<?php

namespace Tests\Feature\Barang;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesProductImports;
use Tests\TestCase;

class ImportCommitEndpointTest extends TestCase
{
    use MakesProductImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
    }

    public function test_fix_skip_and_commit_through_api(): void
    {
        $id = $this->makeImport()->id;

        $this->postJson("/barang/impor/{$id}/simpan")->assertJsonValidationErrors('baris');
        $this->putJson("/barang/impor/{$id}/baris/7", ['values' => ['kategori' => 'Makanan']])->assertOk()->assertJsonPath('counts.masalah', 4);
        $this->postJson("/barang/impor/{$id}/baris/13/lewati", ['lewati' => true])->assertOk()->assertJsonPath('counts.masalah', 3);
        $this->postJson("/barang/impor/{$id}/lewati-masalah")->assertOk()->assertJsonPath('counts.masalah', 0);
        $this->putJson("/barang/impor/{$id}/baris/99", ['values' => ['nama' => 'x']])->assertNotFound();

        $this->postJson("/barang/impor/{$id}/simpan")->assertOk()
            ->assertJsonPath('data.status', 'sebagian')->assertJsonPath('data.created', 6)->assertJsonPath('data.updated', 2);
        $this->assertSame('Makanan', Product::where('code', 'MKN005')->first()->category->name);

        // Impor yang sudah disimpan tidak bisa diubah atau disimpan lagi.
        $this->postJson("/barang/impor/{$id}/simpan")->assertJsonValidationErrors('impor');
        $this->putJson("/barang/impor/{$id}/baris/2", ['values' => ['nama' => 'x']])->assertJsonValidationErrors('impor');
    }

    public function test_nothing_to_save_and_permissions(): void
    {
        $csv = "kode;nama;kategori;harga_jual\nATK002;Pulpen Hitam;Alat Tulis;3000\n";
        $id = $this->makeImport(content: $csv)->id;
        $this->postJson("/barang/impor/{$id}/simpan")->assertJsonValidationErrors(['baris' => 'Tidak ada baris yang perlu disimpan.']);

        $this->loginAs('kasir@koperasi.test');
        $this->postJson("/barang/impor/{$id}/simpan")->assertForbidden();
        $this->postJson("/barang/impor/{$id}/lewati-masalah")->assertForbidden();
    }
}
