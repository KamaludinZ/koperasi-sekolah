<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Import;
use App\Models\ProductImport;
use Illuminate\Support\Facades\Storage;
use App\Support\ProductImportFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class ImportUploadPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csv(string $name = 'barang.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, implode(';', ProductImportFormat::headers())."\nATK010;Spidol;Alat Tulis;8000;6500;24;5;pcs;ya\n");
    }

    public function test_admin_and_pengurus_open_upload_page_kasir_cannot(): void
    {
        $this->get('/barang/impor')->assertOk()->assertSee('Impor Barang dari Berkas')->assertSee('harga_jual')
            ->assertSee('Templat Excel')->assertSee('maks. 5 MB', false);
        $this->get('/barang/kategori')->assertSee(route('barang.impor'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/barang/impor')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/barang/impor')->assertForbidden();
    }

    public function test_valid_file_is_accepted_with_options(): void
    {
        Livewire::test(Import::class)
            ->set('file', $this->csv())->assertHasNoErrors()->assertSee('barang.csv')
            ->set('mode', 'perbarui')->set('updateStock', true)
            ->call('upload')->assertHasNoErrors()->assertSet('file', null)
            ->assertRedirect(route('barang.impor.pratinjau', ProductImport::latest('id')->value('id')));

        $import = ProductImport::latest('id')->first();
        $this->assertSame(['barang.csv', 'csv', 'pratinjau', 'perbarui', true, 'Admin Koperasi', 1],
            [$import->file_name, $import->format, $import->status, $import->mode, $import->update_stock, $import->user->name, $import->total_rows]);
    }

    public function test_update_stock_is_ignored_when_only_adding(): void
    {
        Livewire::test(Import::class)->set('file', $this->csv())->set('updateStock', true)->set('mode', 'tambah')->call('upload');
        $this->assertFalse(ProductImport::latest('id')->value('update_stock'));
    }

    public function test_unreadable_file_goes_to_detail_with_reason(): void
    {
        Livewire::test(Import::class)->set('file', UploadedFile::fake()->createWithContent('salah.csv', "kode barang;harga\nA;1\n"))
            ->call('upload')->assertRedirect(route('barang.impor.detail', ProductImport::latest('id')->value('id')));
        $this->assertSame('gagal', ProductImport::latest('id')->value('status'));
        $this->get(route('barang.impor.detail', ProductImport::latest('id')->value('id')))->assertSee('Kolom wajib tidak ditemukan');
    }

    public function test_rejects_missing_wrong_or_dangerous_files(): void
    {
        Livewire::test(Import::class)->call('upload')->assertHasErrors(['file' => 'required']);
        Livewire::test(Import::class)->set('file', UploadedFile::fake()->createWithContent('barang.pdf', '%PDF-1.4'))->assertHasErrors('file');

        $path = tempnam(sys_get_temp_dir(), 'x');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString('xl/vbaProject.bin', 'x');
        $zip->close();
        Livewire::test(Import::class)->set('file', UploadedFile::fake()->createWithContent('barang.xlsx', file_get_contents($path)))
            ->assertHasErrors('file')->assertSee('makro');

        Livewire::test(Import::class)->set('file', $this->csv())->call('removeFile')->assertSet('file', null);
        Livewire::test(Import::class)->set('file', $this->csv())->set('mode', 'hapus')->call('upload')->assertHasErrors('mode');
    }

    public function test_templates_download(): void
    {
        $csv = $this->get('/barang/impor/templat/csv')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="templat-impor-barang.csv"');
        $this->assertStringContainsString('kode;nama;kategori;harga_jual', $csv->getContent());

        $xlsx = $this->get('/barang/impor/templat/xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $xlsx->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<t xml:space="preserve">harga_jual</t>', $sheet);
        $this->assertStringContainsString('Jus Jeruk Kotak', $sheet);
        $zip->close();

        // Templat Excel lolos pemeriksaan unggah sendiri.
        $file = UploadedFile::fake()->createWithContent('templat.xlsx', file_get_contents($path));
        Livewire::test(Import::class)->set('file', $file)->assertHasNoErrors();

        $this->get('/barang/impor/templat/pdf')->assertNotFound();
    }
}
