<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\ImportPreview;
use App\Models\ActivityLog;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MakesProductImports;
use Tests\TestCase;
use ZipArchive;

class ImportHistoryEndpointTest extends TestCase
{
    use MakesProductImports;
    use RefreshDatabase;

    private int $done;

    private int $pending;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
        $done = $this->makeImport('tambah', name: 'seragam baru.csv');
        Livewire::test(ImportPreview::class, ['import' => $done->id])->call('skipProblems')->call('commit');
        $this->done = $done->id;
        $this->pending = $this->makeImport(name: 'ganjil.csv')->id;
        $this->makeImport(content: "x;y\n1;2\n", name: 'rusak.csv');
    }

    public function test_list_filter_and_counts(): void
    {
        $this->getJson('/barang/impor/data')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('counts.gagal', 1)->assertJsonPath('counts.sebagian', 1)->assertJsonPath('counts.pratinjau', 1)
            ->assertJsonPath('data.0.file_name', 'rusak.csv');
        $this->getJson('/barang/impor/data?status=sebagian')->assertJsonCount(1, 'data')->assertJsonPath('data.0.committed_by', 'Admin Koperasi');
        $this->getJson('/barang/impor/data?q=GANJIL')->assertJsonCount(1, 'data');
        $this->getJson('/barang/impor/data?status=hilang')->assertJsonValidationErrors('status');
    }

    public function test_show_returns_problems(): void
    {
        $this->getJson("/barang/impor/{$this->done}/data")->assertOk()
            ->assertJsonPath('data.created', 5)->assertJsonCount(8, 'problems')
            ->assertJsonPath('problems.0.message', 'Kode sudah ada (mode hanya tambah)');
    }

    public function test_report_downloads_results_per_row(): void
    {
        $csv = $this->get("/barang/impor/{$this->done}/hasil")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=hasil-impor-seragam-baru.csv')->streamedContent();
        $this->assertStringContainsString("4;ATK010;\"Spidol Papan Tulis\";baru;", $csv);
        $this->assertStringContainsString('Kode sudah ada (mode hanya tambah)', $csv);

        $preview = $this->get("/barang/impor/{$this->pending}/hasil")->streamedContent();
        $this->assertStringContainsString('akan diperbarui', $preview);
        $this->assertStringContainsString('bermasalah', $preview);

        $xlsx = $this->get("/barang/impor/{$this->done}/hasil?format=xlsx")->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $xlsx);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertStringContainsString('Sebagian tersimpan', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();

        $this->assertSame(3, ActivityLog::where('event', 'import.report_downloaded')->count());
        $this->getJson("/barang/impor/{$this->done}/hasil?format=pdf")->assertJsonValidationErrors('format');

        $this->loginAs('kasir@koperasi.test');
        $this->get("/barang/impor/{$this->done}/hasil")->assertForbidden();
        $this->getJson('/barang/impor/data')->assertForbidden();
    }
}
