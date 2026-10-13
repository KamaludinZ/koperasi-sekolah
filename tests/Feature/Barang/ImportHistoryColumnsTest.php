<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\ImportDetail;
use App\Livewire\Barang\ImportPreview;
use App\Models\ProductImport;
use App\Services\RetentionService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MakesProductImports;
use Tests\TestCase;

class ImportHistoryColumnsTest extends TestCase
{
    use MakesProductImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
    }

    public function test_columns_warnings_and_committer(): void
    {
        $this->assertTrue(Schema::hasColumns('product_imports', ['committed_by', 'warnings']));

        $csv = "kode;nama;kategori;harga_jual;warna;Ukuran\nATK030;Krayon;Alat Tulis;12000;merah;besar\n";
        $import = $this->makeImport(content: $csv, name: 'krayon.csv');
        $this->assertSame(['Kolom diabaikan karena tidak dikenal: "warna", "Ukuran".'], $import->warnings);
        $this->get(route('barang.impor.pratinjau', $import->id))->assertSee('Kolom diabaikan karena tidak dikenal');

        $this->loginAs('pengurus@koperasi.test');
        Livewire::test(ImportPreview::class, ['import' => $import->id])->call('commit');
        $import->refresh();
        $this->assertSame(['Pak Dedi', 'Admin Koperasi'], [$import->committer->name, $import->user->name]);
        Livewire::test(ImportDetail::class, ['import' => $import->id])->assertSee('Disimpan oleh')->assertSee('Pak Dedi');
    }

    public function test_stale_previews_are_abandoned_by_retention(): void
    {
        $this->travelTo('2026-09-01');
        $old = $this->makeImport(name: 'lama.csv');
        $path = $old->file_path;
        $this->travelTo('2026-10-14');
        $fresh = $this->makeImport(name: 'baru.csv');

        $this->assertSame(1, app(RetentionService::class)->run(dryRun: true)['impor']);
        $this->assertSame(1, app(RetentionService::class)->run()['impor']);

        $old->refresh();
        $this->assertSame('gagal', $old->status);
        $this->assertStringContainsString('dibatalkan otomatis', $old->file_error);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('pratinjau', $fresh->fresh()->status);
        $this->assertSame(ProductImport::count(), 2);
    }
}
