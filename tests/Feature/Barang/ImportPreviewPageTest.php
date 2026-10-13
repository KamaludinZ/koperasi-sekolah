<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\ImportPreview;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\ProductImportFormat;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MakesProductImports;
use Tests\TestCase;

class ImportPreviewPageTest extends TestCase
{
    use MakesProductImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
    }

    private function row(array $rows, int $line): array
    {
        return collect($rows)->firstWhere('line', $line);
    }

    public function test_access_and_unknown_import(): void
    {
        $import = $this->makeImport();
        $this->get("/barang/impor/{$import->id}/pratinjau")->assertOk()->assertSee('barang-semester-ganjil.csv')->assertSee('Bermasalah')->assertDontSee('Data contoh');
        $this->get('/barang/kategori')->assertSee(route('barang.impor.pratinjau', $import->id));
        $this->get('/barang/impor/999/pratinjau')->assertNotFound();
        $this->loginAs('kasir@koperasi.test');
        $this->get("/barang/impor/{$import->id}/pratinjau")->assertForbidden();
    }

    public function test_rows_are_classified_against_real_products_and_categories(): void
    {
        $c = Livewire::test(ImportPreview::class, ['import' => $this->makeImport()->id]);
        $rows = $c->instance()->rows;

        $this->assertSame('perbarui', $this->row($rows, 2)['action']);
        $this->assertSame(['4000', '4500'], $this->row($rows, 2)['changes']['harga_jual']);
        $this->assertSame(['lewati', 'Tidak ada perubahan'], [$this->row($rows, 3)['action'], $this->row($rows, 3)['reason']]);
        $this->assertSame(['baru', 'ATK010'], [$this->row($rows, 4)['action'], $this->row($rows, 4)['values']['kode']]);
        $this->assertSame('2500', $this->row($rows, 5)['values']['harga_jual']);
        $this->assertArrayHasKey('kategori', $this->row($rows, 7)['errors']);
        $this->assertArrayHasKey('nama', $this->row($rows, 9)['errors']);
        $this->assertArrayHasKey('harga_jual', $this->row($rows, 10)['errors']);
        $this->assertArrayHasKey('harga_modal', $this->row($rows, 12)['errors']);
        $this->assertArrayHasKey('satuan', $this->row($rows, 12)['errors']);
        $this->assertSame('Kode sama dengan baris 4.', $this->row($rows, 13)['errors']['kode']);
        $this->assertSame(['aktif' => ['ya', 'tidak']], $this->row($rows, 14)['changes']);

        $this->assertSame(['semua' => 13, 'masalah' => 5, 'baru' => 5, 'perbarui' => 2, 'lewati' => 1], $c->instance()->counts);
        $c->call('setFilter', 'masalah');
        $this->assertCount(5, $c->instance()->visible);
    }

    public function test_fix_skip_and_commit_writes_products(): void
    {
        $import = $this->makeImport();
        $c = Livewire::test(ImportPreview::class, ['import' => $import->id])
            ->call('commit')->assertDispatched('toast', type: 'error')->assertSet('filter', 'masalah');

        $c->call('edit', 9)->assertSet('draft.kode', 'MNM005')->set('draft.nama', 'Air Mineral 1,5 L')->call('saveRow')
            ->assertDispatched('toast', type: 'success', message: 'Baris 9 sudah benar.');
        $this->assertSame('baru', $this->row($c->instance()->rows, 9)['action']);
        $c->call('edit', 10)->set('draft.harga_jual', 'tetap salah')->call('saveRow')->assertDispatched('toast', type: 'error');

        $c->call('skipProblems');
        $this->assertSame(0, $c->instance()->counts['masalah']);
        $c->call('toggleSkip', 13); // disertakan lagi → bermasalah (kode dobel)
        $this->assertSame('masalah', $this->row($c->instance()->rows, 13)['action']);
        $c->call('toggleSkip', 13);

        $c->call('commit')->assertRedirect(route('barang.impor.detail', $import->id));

        $import->refresh();
        $this->assertSame(['sebagian', 6, 2, 5], [$import->status, $import->created, $import->updated, $import->skipped]);
        $this->assertNull($import->file_path);
        $this->assertSame(4500, (int) Product::where('code', 'ATK001')->value('price'));
        $this->assertFalse((bool) Product::where('code', 'SRG002')->value('is_active'));
        $spidol = Product::where('code', 'ATK010')->firstOrFail();
        $this->assertSame([24, 'Alat Tulis', 6500], [$spidol->stock, $spidol->category->name, (int) $spidol->cost_price]);
        $this->assertSame('Air Mineral 1,5 L', Product::where('code', 'MNM005')->value('name'));
        $this->assertTrue(StockMovement::where('product_id', $spidol->id)->where('note', 'Stok awal')->exists());
        $this->assertSame('Impor barang-semester-ganjil.csv', ActivityLog::where('event', 'product.created')->latest('id')->value('reason'));
        $this->assertSame(['dilewati', 'Tidak ada perubahan'], [$import->rows()->where('line', 3)->value('result'), $import->rows()->where('line', 3)->value('message')]);

        Livewire::test(ImportPreview::class, ['import' => $import->id])->assertSee('Impor ini sudah disimpan')
            ->call('edit', 2)->assertSet('editing', null);
    }

    public function test_modes_and_stock_update(): void
    {
        $rows = Livewire::test(ImportPreview::class, ['import' => $this->makeImport('tambah')->id])->instance()->rows;
        $this->assertSame('Kode sudah ada (mode hanya tambah)', $this->row($rows, 2)['reason']);

        $csv = implode(';', ProductImportFormat::headers())."\nATK001;Buku Tulis 38 Lembar;Alat Tulis;4000;3200;150;20;pcs;ya\n";
        $import = $this->makeImport('perbarui', true, $csv, 'stok.csv');
        $c = Livewire::test(ImportPreview::class, ['import' => $import->id]);
        $this->assertSame(['120', '150'], $c->instance()->rows[0]['changes']['stok']);
        $c->call('commit');
        $this->assertSame(150, Product::where('code', 'ATK001')->value('stock'));
        $this->assertTrue(\App\Models\StockMovement::where('stock_after', 150)->exists());
    }

    public function test_shared_validation_rules(): void
    {
        $row = ProductImportFormat::clean(['kode' => ' abc-1 ', 'nama' => 'Pensil  Warna', 'kategori' => 'alat tulis', 'harga_jual' => 'Rp 12.000', 'aktif' => 'TIDAK']);
        $this->assertSame(['ABC-1', 'Pensil Warna', '12000', 'tidak'], [$row['kode'], $row['nama'], $row['harga_jual'], $row['aktif']]);
        $this->assertSame([], ProductImportFormat::validateRow($row, ['Alat Tulis']));
        $this->assertFalse(ProductImportFormat::isActive('tidak'));
        $this->assertTrue(ProductImportFormat::isActive(''));
    }
}
