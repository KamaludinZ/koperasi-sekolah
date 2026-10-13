<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\ImportDetail;
use App\Livewire\Barang\ImportHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImportHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_access(): void
    {
        $this->get('/barang/impor/riwayat')->assertOk()->assertSee('Riwayat Impor Barang')->assertSee('seragam-baru.csv')
            ->assertSee(route('barang.impor.detail', 48))->assertSee(route('barang.impor.pratinjau', 50));
        $this->get('/barang/kategori')->assertSee(route('barang.impor.riwayat'))->assertSee(route('barang.impor.detail', 49));
        $this->get('/barang/impor/48')->assertOk()->assertSee('Sabuk Pramuka');
        $this->get('/barang/impor/999')->assertNotFound();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/barang/impor/riwayat')->assertForbidden();
        $this->get('/barang/impor/48')->assertForbidden();
    }

    public function test_list_filters_and_totals(): void
    {
        $c = Livewire::test(ImportHistory::class);
        $this->assertSame(['imports' => 5, 'created' => 28, 'updated' => 12, 'failed' => 6], array_slice($c->instance()->totals, 0, 4));
        $this->assertSame(1, $c->instance()->totals['by_status']['gagal']);

        $c->call('setStatus', 'sebagian');
        $this->assertSame(['seragam-baru.csv'], array_column($c->instance()->imports, 'file_name'));
        $c->call('setStatus', 'sebagian')->assertSet('status', '');
        $c->set('search', 'admin');
        $this->assertCount(2, $c->instance()->imports);

        $this->assertSame('2 menit', ImportHistory::duration(collect($c->instance()->all)->firstWhere('id', 47)));
        $this->assertNull(ImportHistory::duration(collect($c->instance()->all)->firstWhere('id', 50)));
    }

    public function test_detail_shows_problems_and_downloads_them(): void
    {
        Livewire::test(ImportDetail::class, ['import' => 48])
            ->assertSee('Baris tidak tersimpan')->assertSee('Kategori &quot;Pramuka&quot; belum ada.', false)->assertSee('Hanya tambah baru');

        $c = Livewire::test(ImportDetail::class, ['import' => 48])->call('downloadProblems');
        $c->assertFileDownloaded('baris-bermasalah-seragam-baru.csv');

        Livewire::test(ImportDetail::class, ['import' => 46])->assertSee('Berkas tidak dapat dibaca')->assertSee('Judul kolom tidak dikenali')
            ->assertDontSee('Unduh baris bermasalah');
        Livewire::test(ImportDetail::class, ['import' => 49])->assertSee('Semua baris berhasil disimpan.');
        Livewire::test(ImportDetail::class, ['import' => 50])->assertSee('Buka pratinjau');
    }

    public function test_summary_and_problem_rows_can_be_copied(): void
    {
        $c = Livewire::test(ImportDetail::class, ['import' => 48]);
        $this->assertSame('Impor barang seragam-baru.csv (08 Okt 2026 11:15, oleh Pak Dedi) — Sebagian tersimpan. 18 baris: 14 baru, 0 diperbarui, 1 dilewati, 3 gagal.',
            $c->instance()->summaryText);
        $tsv = explode("\n", $c->instance()->problemsTsv);
        $this->assertSame("baris\tkode\tnama\tstatus\talasan", $tsv[0]);
        $this->assertSame("6\tSRG010\tSabuk Pramuka\tgagal\tKategori \"Pramuka\" belum ada.", $tsv[1]);
        $this->assertCount(5, $tsv);
        $c->assertSee('Salin ringkasan')->assertSee('Salin baris')->assertSee('$copy', false);

        $failed = Livewire::test(ImportDetail::class, ['import' => 46]);
        $this->assertStringContainsString('Judul kolom tidak dikenali', $failed->instance()->summaryText);
        $failed->assertDontSee('Salin baris');
    }

    public function test_result_report_downloads_as_csv_and_excel(): void
    {
        Livewire::test(ImportDetail::class, ['import' => 48])->call('download', 'csv')->assertFileDownloaded('hasil-impor-seragam-baru.csv');
        Livewire::test(ImportDetail::class, ['import' => 48])->call('download', 'xlsx')->assertFileDownloaded('hasil-impor-seragam-baru.xlsx');
    }
}
