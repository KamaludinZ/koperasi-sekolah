<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Catalog;
use App\Livewire\Barang\ImportDetail;
use App\Livewire\Barang\ImportHistory;
use App\Livewire\Barang\ImportPreview;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MakesProductImports;
use Tests\TestCase;

class ImportHistoryPageTest extends TestCase
{
    use MakesProductImports;
    use RefreshDatabase;

    private int $done;

    private int $failed;

    private int $pending;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);

        $this->travelTo('2026-09-01 09:00:00');
        $this->failed = $this->makeImport(content: "kode barang;harga\nA;1\n", name: 'salah-format.csv')->id;

        $this->travelTo('2026-10-08 11:15:00');
        $done = $this->makeImport('tambah', name: 'seragam-baru.csv');
        Livewire::test(ImportPreview::class, ['import' => $done->id])->call('skipProblems');
        $this->travel(1)->minute();
        Livewire::test(ImportPreview::class, ['import' => $done->id])->call('commit');
        $this->done = $done->id;

        $this->travelTo('2026-10-13 09:15:00');
        $this->pending = $this->makeImport(name: 'barang-semester-ganjil.csv')->id;
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_access(): void
    {
        $this->get('/barang/impor/riwayat')->assertOk()->assertSee('Riwayat Impor Barang')->assertSee('seragam-baru.csv')
            ->assertSee(route('barang.impor.detail', $this->done))->assertSee(route('barang.impor.pratinjau', $this->pending));
        $this->get('/barang/kategori')->assertSee(route('barang.impor.riwayat'))->assertSee(route('barang.impor.detail', $this->done));
        $this->get("/barang/impor/{$this->done}")->assertOk()->assertSee('Kacang Atom');
        $this->get('/barang/impor/999')->assertNotFound();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/barang/impor/riwayat')->assertForbidden();
        $this->get("/barang/impor/{$this->done}")->assertForbidden();
    }

    public function test_list_filters_totals_and_catalog_summary(): void
    {
        $c = Livewire::test(ImportHistory::class);
        $t = $c->instance()->totals;
        $this->assertSame([3, 5, 0, 0], [$t['imports'], $t['created'], $t['updated'], $t['failed']]);
        $this->assertSame([1, 1, 1], [$t['by_status']['gagal'], $t['by_status']['sebagian'], $t['by_status']['pratinjau']]);

        $c->call('setStatus', 'sebagian');
        $this->assertSame(['seragam-baru.csv'], array_column($c->instance()->imports, 'file_name'));
        $c->call('setStatus', 'sebagian')->set('search', 'ganjil');
        $this->assertSame(['barang-semester-ganjil.csv'], array_column($c->instance()->imports, 'file_name'));
        $this->assertSame('1 menit', ImportHistory::duration(collect($c->instance()->all)->firstWhere('id', $this->done)));
        $this->assertNull(ImportHistory::duration(collect($c->instance()->all)->firstWhere('id', $this->pending)));

        $s = Livewire::test(Catalog::class)->instance()->summary;
        $this->assertSame([2, 5, 'barang-semester-ganjil.csv'], [$s['imports_30d'], $s['imported_rows_30d'], $s['pending']['file_name']]);
    }

    public function test_detail_problems_copy_and_downloads(): void
    {
        $c = Livewire::test(ImportDetail::class, ['import' => $this->done])
            ->assertSee('Baris tidak tersimpan')->assertSee('Kode sudah ada (mode hanya tambah)')->assertSee('Hanya tambah baru');
        $this->assertStringStartsWith('Impor barang seragam-baru.csv (08 Okt 2026 11:15, oleh Admin Koperasi) — Sebagian tersimpan. 13 baris: 5 baru, 0 diperbarui, 8 dilewati, 0 gagal.', $c->instance()->summaryText);
        $tsv = explode("\n", $c->instance()->problemsTsv);
        $this->assertSame("baris\tkode\tnama\tstatus\talasan", $tsv[0]);
        $this->assertCount(9, $tsv);
        $c->call('downloadProblems')->assertFileDownloaded('baris-bermasalah-seragam-baru.csv');
        Livewire::test(ImportDetail::class, ['import' => $this->done])->call('download', 'xlsx')->assertFileDownloaded('hasil-impor-seragam-baru.xlsx');
        Livewire::test(ImportDetail::class, ['import' => $this->done])->call('download', 'csv')->assertFileDownloaded('hasil-impor-seragam-baru.csv');

        Livewire::test(ImportDetail::class, ['import' => $this->failed])->assertSee('Berkas tidak dapat dibaca')
            ->assertSee('Kolom wajib tidak ditemukan')->assertDontSee('Unduh baris bermasalah');
        Livewire::test(ImportDetail::class, ['import' => $this->pending])->assertSee('Buka pratinjau');
    }
}
