<?php

namespace Tests\Feature\Laporan;

use App\Livewire\Laporan\Index;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\SalesExport;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesExportEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 10:00:00');

        $andi = Member::factory()->create(['name' => 'Andi', 'class' => '7A']);
        Transaction::factory()->withItems([$this->pid('ATK001') => 2])->at('2026-10-14 08:15:00')
            ->create(['invoice_number' => 'INV-20261014-0001', 'member_id' => $andi->id]);
        Transaction::factory()->qris()->withItems([$this->pid('ATK002') => 1])->at('2026-10-13 09:40:00')
            ->create(['invoice_number' => 'INV-20261013-0001']);
        Transaction::factory()->withItems([$this->pid('ATK001') => 1])->at('2026-09-30 09:00:00')->create();
    }

    public function test_csv_with_default_sections(): void
    {
        $res = $this->get('/laporan/unduh?dari=2026-10-01&sampai=2026-10-14')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('rekap-penjualan-20261001-20261014.csv');
        $csv = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Total penjualan";11000', $csv);
        $this->assertStringContainsString('"Laba kotor";2200', $csv);
        $this->assertStringContainsString('2026-10-13;1;1;3000', $csv);
        $this->assertStringContainsString('"Buku Tulis 38 Lembar";2;8000;1600', $csv);
        $this->assertStringNotContainsString('RINCIAN TRANSAKSI', $csv);
    }

    public function test_csv_transaction_details_in_time_order(): void
    {
        $csv = $this->get('/laporan/unduh?dari=2026-10-01&sampai=2026-10-14&isi[]=transactions')->streamedContent();

        $this->assertStringNotContainsString('RINGKASAN', $csv);
        $this->assertLessThan(strpos($csv, 'INV-20261014-0001'), strpos($csv, 'INV-20261013-0001'));
        $this->assertStringContainsString('"Andi (7A)";Tunai;2;8000', $csv);
        $this->assertStringContainsString('"QRIS / Transfer"', $csv);
    }

    public function test_pdf_download(): void
    {
        $pdf = $this->get('/laporan/unduh?dari=2026-10-01&sampai=2026-10-14&format=pdf&isi[]=summary&isi[]=transactions')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('rekap-penjualan-20261001-20261014.pdf')
            ->streamedContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_validation(): void
    {
        $this->getJson('/laporan/unduh')->assertJsonValidationErrors(['dari', 'sampai']);
        $this->getJson('/laporan/unduh?dari=2026-10-10&sampai=2026-10-01')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/unduh?dari=2026-10-01&sampai=2026-10-20')->assertJsonValidationErrors('sampai');
        $this->getJson('/laporan/unduh?dari=2025-01-01&sampai=2026-10-14')->assertJsonValidationErrors(['sampai' => 'Rentang maksimal 1 tahun.']);
        $this->getJson('/laporan/unduh?dari=2026-10-01&sampai=2026-10-14&format=xlsx')->assertJsonValidationErrors('format');
        $this->getJson('/laporan/unduh?dari=2026-10-01&sampai=2026-10-14&isi[]=stok')->assertJsonValidationErrors('isi.0');
    }

    public function test_pdf_rejects_too_many_transaction_rows(): void
    {
        config()->set('app.debug', false);
        $limit = SalesExport::PDF_MAX_TRANSACTIONS;
        Transaction::factory()->count($limit)->at('2026-10-02 08:00:00')->create();

        $this->getJson('/laporan/unduh?dari=2026-10-01&sampai=2026-10-14&format=pdf&isi[]=transactions')
            ->assertJsonValidationErrors('isi');
        $this->get('/laporan/unduh?dari=2026-10-01&sampai=2026-10-14&isi[]=transactions')->assertOk(); // CSV tetap boleh
    }

    public function test_page_panel_downloads_pdf(): void
    {
        Livewire::test(Index::class)
            ->set('exportFrom', '2026-10-01')
            ->set('exportTo', '2026-10-14')
            ->set('exportFormat', 'pdf')
            ->call('export')
            ->assertHasNoErrors()
            ->assertFileDownloaded('rekap-penjualan-20261001-20261014.pdf');
    }
}
