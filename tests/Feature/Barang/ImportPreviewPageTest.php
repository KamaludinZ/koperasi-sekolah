<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\ImportPreview;
use App\Support\MockData;
use App\Support\ProductImportFormat;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImportPreviewPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
    }

    private function row(array $rows, int $line): array
    {
        return collect($rows)->firstWhere('line', $line);
    }

    public function test_access_and_unknown_import(): void
    {
        $this->get('/barang/impor/50/pratinjau')->assertOk()->assertSee('barang-semester-ganjil.xlsx')->assertSee('Bermasalah');
        $this->get('/barang/kategori')->assertSee(route('barang.impor.pratinjau', 50));
        $this->get('/barang/impor/999/pratinjau')->assertNotFound();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/barang/impor/50/pratinjau')->assertForbidden();
    }

    public function test_rows_are_classified_against_real_products_and_categories(): void
    {
        $c = Livewire::test(ImportPreview::class, ['import' => 50]);
        $rows = $c->instance()->rows;

        $this->assertSame('perbarui', $this->row($rows, 2)['action']);              // ATK001 harga 4.000 → 4.500
        $this->assertSame(['4000.00', '4500'], $this->row($rows, 2)['changes']['harga_jual']);
        $this->assertSame('lewati', $this->row($rows, 3)['action']);                // ATK002 sama persis
        $this->assertSame('Tidak ada perubahan', $this->row($rows, 3)['reason']);
        $this->assertSame('baru', $this->row($rows, 4)['action']);                  // atk010 → ATK010
        $this->assertSame('ATK010', $this->row($rows, 4)['values']['kode']);
        $this->assertSame('2500', $this->row($rows, 5)['values']['harga_jual']);   // "Rp 2.500"
        $this->assertArrayHasKey('kategori', $this->row($rows, 7)['errors']);      // Snack belum ada
        $this->assertArrayHasKey('nama', $this->row($rows, 9)['errors']);
        $this->assertArrayHasKey('harga_jual', $this->row($rows, 10)['errors']);
        $this->assertArrayHasKey('harga_modal', $this->row($rows, 12)['errors']);  // modal > jual
        $this->assertArrayHasKey('satuan', $this->row($rows, 12)['errors']);
        $this->assertSame('Kode sama dengan baris 4.', $this->row($rows, 13)['errors']['kode']);
        $this->assertSame(['aktif' => ['ya', 'tidak']], $this->row($rows, 14)['changes']);

        $this->assertSame(['semua' => 13, 'masalah' => 5, 'baru' => 5, 'perbarui' => 2, 'lewati' => 1], $c->instance()->counts);
        $c->call('setFilter', 'masalah');
        $this->assertCount(5, $c->instance()->visible);
    }

    public function test_fix_skip_and_commit(): void
    {
        $c = Livewire::test(ImportPreview::class, ['import' => 50])
            ->call('commit')->assertDispatched('toast', type: 'error')->assertSet('filter', 'masalah');

        $c->call('edit', 9)->assertSet('draft.kode', 'MNM005')->set('draft.nama', 'Air Mineral 1,5 L')->call('saveRow')
            ->assertDispatched('toast', type: 'success', message: 'Baris 9 sudah benar.');
        $this->assertSame('baru', $this->row($c->instance()->rows, 9)['action']);

        $c->call('edit', 10)->set('draft.harga_jual', 'tetap salah')->call('saveRow')->assertDispatched('toast', type: 'error');

        $c->call('skipProblems');
        $this->assertSame(0, $c->instance()->counts['masalah']);
        $c->call('toggleSkip', 13); // disertakan lagi → kembali bermasalah (kode dobel)
        $this->assertSame('masalah', $this->row($c->instance()->rows, 13)['action']);

        $c->call('toggleSkip', 13)->call('commit')->assertRedirect(route('barang.kategori'));
        $done = collect(MockData::importHistory())->firstWhere('id', 50);
        $this->assertSame('sebagian', $done['status']);
        $this->assertSame([6, 2], [$done['created'], $done['updated']]);

        Livewire::test(ImportPreview::class, ['import' => 50])->assertSee('Impor ini sudah disimpan')
            ->call('edit', 2)->assertSet('editing', null);
    }

    public function test_modes_skip_rows_by_code_existence(): void
    {
        $imp = MockData::createImport('x.csv', 'csv', 'tambah', false, 'Admin Koperasi');
        $rows = Livewire::test(ImportPreview::class, ['import' => $imp['id']])->instance()->rows;
        $this->assertSame('Kode sudah ada (mode hanya tambah)', $this->row($rows, 2)['reason']);

        $imp = MockData::createImport('y.csv', 'csv', 'perbarui', true, 'Admin Koperasi');
        $rows = Livewire::test(ImportPreview::class, ['import' => $imp['id']])->instance()->rows;
        $this->assertSame('Kode baru (mode hanya perbarui)', $this->row($rows, 4)['reason']);
        $this->assertArrayNotHasKey('stok', $this->row($rows, 2)['changes']); // stok sama (120)
        $this->assertSame([85, '85'], [85, $this->row($rows, 3)['values']['stok']]);
    }

    public function test_shared_validation_rules(): void
    {
        $row = ProductImportFormat::clean(['kode' => ' abc-1 ', 'nama' => 'Pensil  Warna', 'kategori' => 'alat tulis', 'harga_jual' => 'Rp 12.000', 'aktif' => 'TIDAK']);
        $this->assertSame(['ABC-1', 'Pensil Warna', '12000', 'tidak'], [$row['kode'], $row['nama'], $row['harga_jual'], $row['aktif']]);
        $this->assertSame([], ProductImportFormat::validateRow($row, ['Alat Tulis']));
        $this->assertFalse(ProductImportFormat::isActive('tidak'));
        $this->assertTrue(ProductImportFormat::isActive(''));
        $this->assertArrayHasKey('kode', ProductImportFormat::validateRow([...$row, 'kode' => 'A B'], ['Alat Tulis']));
    }
}
