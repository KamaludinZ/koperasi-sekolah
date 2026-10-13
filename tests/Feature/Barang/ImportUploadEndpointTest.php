<?php

namespace Tests\Feature\Barang;

use App\Models\ActivityLog;
use App\Models\ProductImport;
use App\Services\ProductImportService;
use App\Support\ProductImportFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportUploadEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csv(string $name = 'barang.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, ProductImportFormat::csvTemplate());
    }

    public function test_upload_stores_file_privately_and_records_import(): void
    {
        $res = $this->postJson('/barang/impor', ['berkas' => $this->csv('data barang.csv'), 'mode' => 'perbarui', 'perbarui_stok' => true])
            ->assertCreated()->assertJsonPath('data.status', 'diunggah')->assertJsonPath('data.mode', 'perbarui')
            ->assertJsonPath('data.update_stock', true)->assertJsonPath('data.file_name', 'data barang.csv')->assertJsonPath('data.user', 'Admin Koperasi');

        $import = ProductImport::findOrFail($res->json('data.id'));
        Storage::disk('local')->assertExists($import->file_path);
        $this->assertStringStartsWith('imports/'.now()->format('Y/m').'/', $import->file_path);
        $this->assertStringEndsWith('.csv', $import->file_path);
        $this->assertSame('import.uploaded', ActivityLog::latest('id')->value('event'));
    }

    public function test_update_stock_ignored_for_add_only_mode(): void
    {
        $this->postJson('/barang/impor', ['berkas' => $this->csv(), 'mode' => 'tambah', 'perbarui_stok' => true])
            ->assertCreated()->assertJsonPath('data.update_stock', false);
    }

    public function test_rejects_invalid_files_and_options(): void
    {
        $this->postJson('/barang/impor', ['mode' => 'semua'])->assertJsonValidationErrors('berkas');
        $this->postJson('/barang/impor', ['berkas' => UploadedFile::fake()->createWithContent('x.php.csv', 'a;b'), 'mode' => 'semua'])
            ->assertJsonValidationErrors(['berkas' => 'Nama atau jenis berkas tidak diizinkan.']);
        $this->postJson('/barang/impor', ['berkas' => UploadedFile::fake()->create('besar.csv', 6000, 'text/csv'), 'mode' => 'semua'])
            ->assertJsonValidationErrors('berkas');
        $this->postJson('/barang/impor', ['berkas' => $this->csv(), 'mode' => 'hapus'])->assertJsonValidationErrors('mode');
        $this->assertSame(0, ProductImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));
    }

    public function test_limits_open_imports_per_user_and_permissions(): void
    {
        foreach (range(1, ProductImportService::MAX_OPEN) as $i) {
            $this->postJson('/barang/impor', ['berkas' => $this->csv(), 'mode' => 'semua'])->assertCreated();
        }
        $this->postJson('/barang/impor', ['berkas' => $this->csv(), 'mode' => 'semua'])->assertJsonValidationErrors('berkas');

        $this->loginAs('kasir@koperasi.test');
        $this->postJson('/barang/impor', ['berkas' => $this->csv(), 'mode' => 'semua'])->assertForbidden();
    }
}
