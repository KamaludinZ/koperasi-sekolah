<?php

namespace Tests\Feature\Barang;

use App\Models\ProductImport;
use App\Services\ProductImportService;
use App\Support\ProductImportFormat;
use App\Support\SimpleXlsx;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ImportParsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
    }

    private function upload(string $name, string $content, string $mode = 'semua', bool $stock = false): ProductImport
    {
        return app(ProductImportService::class)->upload(UploadedFile::fake()->createWithContent($name, $content), $mode, $stock);
    }

    private function sample(): string
    {
        return implode("\n", [
            'Kode Barang;Nama;Kategori;Harga;Harga Beli;Stok;Min Stok;Satuan;Status;Warna',
            'ATK001;Buku Tulis 38 Lembar;Alat Tulis;4.500;3200;120;20;pcs;ya;biru',
            'ATK002;Pulpen Hitam;Alat Tulis;3000;2400;85;20;pcs;ya;',
            'atk010;Spidol Papan Tulis;alat tulis;8000;6500;24;5;pcs;;',
            ';;;;;;;;;',
            'MKN005;Kacang Atom;Snack;2000;1500;30;10;bks;ya;',
            'ATK010;Spidol Biru;Alat Tulis;8000;9000;12;5;pasang;ya;',
        ]);
    }

    public function test_csv_with_aliases_semicolon_and_blank_rows(): void
    {
        $import = $this->upload('barang.csv', "\xEF\xBB\xBF".$this->sample());
        $this->assertSame(['pratinjau', 5], [$import->status, $import->total_rows]);
        $this->assertSame([2, 3, 4, 6, 7], $import->rows->pluck('line')->all()); // baris kosong dilewati, nomor tetap
        $this->assertSame('4.500', $import->rows[0]->values['harga_jual']);

        $e = app(ProductImportService::class)->evaluate($import);
        $by = collect($e['rows'])->keyBy('line');
        $this->assertSame(['perbarui', ['4000', '4500']], [$by[2]['action'], $by[2]['changes']['harga_jual']]);
        $this->assertSame(['lewati', 'Tidak ada perubahan'], [$by[3]['action'], $by[3]['reason']]);
        $this->assertSame(['baru', 'ATK010'], [$by[4]['action'], $by[4]['values']['kode']]);
        $this->assertArrayHasKey('kategori', $by[6]['errors']);
        $this->assertSame(['kode', 'harga_modal', 'satuan'], array_keys($by[7]['errors']));
        $this->assertSame(['semua' => 5, 'masalah' => 2, 'baru' => 1, 'perbarui' => 1, 'lewati' => 1], $e['counts']);
    }

    public function test_comma_tab_and_windows_1252_encoding(): void
    {
        $comma = $this->upload('a.csv', "kode,nama,kategori,harga_jual\nATK020,\"Pensil, isi 12\",Alat Tulis,24000\n");
        $this->assertSame('Pensil, isi 12', $comma->rows[0]->values['nama']);

        $tab = $this->upload('b.csv', "kode\tnama\tkategori\tharga_jual\nATK021\tGunting\tAlat Tulis\t7000\n");
        $this->assertSame('Gunting', $tab->rows[0]->values['nama']);

        $latin = $this->upload('c.csv', mb_convert_encoding("kode;nama;kategori;harga_jual\nMKN030;Kue Bolu Ã¼ Café;Makanan;5000\n", 'Windows-1252', 'UTF-8'));
        $this->assertStringContainsString('Café', $latin->rows[0]->values['nama']);
    }

    public function test_missing_columns_empty_and_too_many_rows_fail_with_reason(): void
    {
        $bad = $this->upload('salah.csv', "kode barang;harga\nATK1;1000\n");
        $this->assertSame('gagal', $bad->status);
        $this->assertStringContainsString('Kolom wajib tidak ditemukan: nama, kategori', $bad->file_error);
        $this->assertNotNull($bad->finished_at);

        $this->assertStringContainsString('belum ada baris', $this->upload('kosong.csv', "kode;nama;kategori;harga_jual\n")->file_error);
        $this->assertSame('Berkas kosong.', $this->upload('blank.csv', "\n \n")->file_error);

        $many = "kode;nama;kategori;harga_jual\n".implode("\n", array_map(fn ($i) => "K{$i};Barang {$i};Alat Tulis;1000", range(1, ProductImportFormat::MAX_ROWS + 1)));
        $this->assertStringContainsString('maksimal 2.000 baris', $this->upload('banyak.csv', $many)->file_error);
    }

    public function test_xlsx_template_and_shared_strings_are_read(): void
    {
        $tpl = $this->upload('templat.xlsx', ProductImportFormat::xlsxTemplate());
        $this->assertSame(['pratinjau', 2], [$tpl->status, $tpl->total_rows]);
        $this->assertSame('Jus Jeruk Kotak', $tpl->rows[1]->values['nama']);

        // XLSX gaya Excel: shared strings, angka, sel kosong di tengah.
        $path = tempnam(sys_get_temp_dir(), 'x');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>kode</t></si><si><t>nama</t></si><si><t>kategori</t></si><si><t>harga_jual</t></si><si><r><t>Penghapus </t></r><r><t>Besar</t></r></si><si><t>Alat Tulis</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="3"><c r="A3"><v>12345</v></c><c r="B3" t="s"><v>4</v></c><c r="C3" t="s"><v>5</v></c><c r="D3"><v>2500.0</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();
        $this->assertSame([['kode', 'nama', 'kategori', 'harga_jual'], [], ['12345', 'Penghapus Besar', 'Alat Tulis', '2500']], SimpleXlsx::read($path));

        $x = $this->upload('excel.xlsx', file_get_contents($path));
        $this->assertSame([3, 'Penghapus Besar', '2500'], [$x->rows[0]->line, $x->rows[0]->values['nama'], $x->rows[0]->values['harga_jual']]);
    }

    public function test_rows_endpoint_and_mode_rules(): void
    {
        $import = $this->upload('barang.csv', $this->sample(), 'tambah');
        $this->getJson("/barang/impor/{$import->id}/baris")->assertOk()
            ->assertJsonPath('data.status', 'pratinjau')->assertJsonPath('counts.semua', 5)
            ->assertJsonPath('rows.0.reason', 'Kode sudah ada (mode hanya tambah)');

        $this->postJson('/barang/impor', ['berkas' => UploadedFile::fake()->createWithContent('x.csv', $this->sample()), 'mode' => 'perbarui'])
            ->assertCreated()->assertJsonPath('data.status', 'pratinjau')->assertJsonPath('data.total_rows', 5);

        $this->loginAs('kasir@koperasi.test');
        $this->getJson("/barang/impor/{$import->id}/baris")->assertForbidden();
    }
}
