<?php

namespace Tests\Feature\Pinjaman;

use App\Jobs\ExportLoanRecap;
use App\Livewire\Pinjaman\Recap;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Models\ReportExport;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use App\Services\LoanRecapExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RecapExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-08-03 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => '=HYPERLINK("x")', 'member_code' => 'G-001']);
        $a = Loan::factory()->create(['code' => 'PJM-A', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 900000, 'interest' => 90000,
            'tenor_months' => 3, 'payment_method' => 'transfer', 'applied_at' => '2026-08-01']);
        app(LoanDisbursementService::class)->disburse($a, ['disbursed_on' => '2026-08-03', 'amount' => 900000]);
        $this->travelTo('2026-09-03 10:00:00');
        app(LoanPaymentService::class)->record($a, ['amount' => 330000, 'paid_at' => '2026-09-03', 'payment_method' => 'transfer', 'reference' => '-TRX-1'], auth()->id());
        $this->travelTo('2026-09-20 10:00:00');
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    public function test_export_from_page_builds_csv_and_marks_downloads(): void
    {
        Livewire::test(Recap::class)->call('setType', 'tahun')->assertSeeHtml('data-export-button')->assertSeeText('Ekspor CSV Tahun 2026')
            ->call('export')->assertDispatched('toast')
            ->assertSeeHtml('data-export-status="done"')->assertSeeText('Belum diunduh');

        $export = ReportExport::where('type', 'pinjaman_rekap')->sole();
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-12-31', 'label' => 'Tahun 2026'], $export->params);
        $this->assertSame('csv', $export->format);
        $this->assertSame('rekap-pinjaman-20260101-20261231.csv', $export->file_name);
        $this->assertSame(2, $export->rows); // 1 pencairan + 1 pembayaran

        $csv = Storage::disk('local')->get($export->file_path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        foreach (['RINGKASAN PERIODE', 'POSISI SAAT INI', 'ANGSURAN PER METODE', 'PER BULAN', 'RINCIAN PENCAIRAN', 'RINCIAN PEMBAYARAN ANGSURAN',
            '"Dana dicairkan";900000', '"Angsuran diterima";330000', '"Porsi bunga";30000', 'Transfer;1;1;330000;300000;30000;100;1;660000'] as $needle) {
            $this->assertStringContainsString($needle, $csv, $needle);
        }
        $this->assertStringContainsString('"\'=HYPERLINK(""x"")"', $csv);     // rumus dinetralkan
        $this->assertStringContainsString(";'-TRX-1;", $csv);
        $this->assertStringNotContainsString(';"=HYPERLINK', $csv);

        $this->get(route('pinjaman.rekap.ekspor.unduh', $export))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('rekap-pinjaman-20260101-20261231.csv');
        $this->get(route('pinjaman.rekap.ekspor.unduh', $export))->assertOk();
        $export->refresh();
        $this->assertSame(2, $export->download_count);
        $this->assertNotNull($export->downloaded_at);
        $this->assertSame(2, ActivityLog::where('event', 'pinjaman.rekap_diunduh')->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'report.exported']);

        Livewire::test(Recap::class)->assertSeeText('Diunduh 2×');
        $this->getJson(route('pinjaman.rekap.ekspor.index'))->assertOk()->assertJsonPath('data.0.download_count', 2);
    }

    public function test_pdf_export_from_page_and_endpoint(): void
    {
        Livewire::test(Recap::class)->set('month', '2026-09')->assertSeeHtml('data-export-format="pdf"')
            ->call('export', 'pdf')->assertSeeHtml('data-export-status="done"');
        $export = ReportExport::where('type', 'pinjaman_rekap')->sole();
        $this->assertSame(['pdf', 'rekap-pinjaman-20260901-20260930.pdf', 1], [$export->format, $export->file_name, $export->rows]);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($export->file_path));
        $this->get(route('pinjaman.rekap.ekspor.unduh', $export))->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('rekap-pinjaman-20260901-20260930.pdf');
        $this->assertSame(1, $export->fresh()->download_count);
        $this->assertDatabaseHas('activity_logs', ['event' => 'report.exported', 'description' => 'Ekspor rekap pinjaman PDF periode 01 Sep 2026 – 30 Sep 2026']);

        $this->postJson(route('pinjaman.rekap.ekspor'), ['bulan' => '2026-08', 'format' => 'pdf'])->assertStatus(202)->assertJsonPath('data.format', 'pdf');
        $this->postJson(route('pinjaman.rekap.ekspor'), ['format' => 'xlsx'])->assertUnprocessable()->assertJsonValidationErrors(['format']);
        Livewire::test(Recap::class)->call('export', 'exe'); // format tak dikenal → CSV
        $this->assertSame('csv', ReportExport::latest('id')->value('format'));
    }

    public function test_pdf_detail_rows_are_capped(): void
    {
        $this->assertSame(500, LoanRecapExport::PDF_MAX_ROWS);
        [$from, $to] = \App\Services\LoanRecapService::period('tahun', '2026');
        $out = app(LoanRecapExport::class)->pdf($from, $to, 'Tahun 2026');
        $this->assertStringStartsWith('%PDF-', $out['content']);
        $this->assertSame(1, $out['rows']);
    }

    public function test_store_endpoint_validation_and_limits(): void
    {
        Queue::fake();
        $this->postJson(route('pinjaman.rekap.ekspor'), ['jenis' => 'rentang', 'dari' => '2026-08-01', 'sampai' => '2026-09-20'])->assertStatus(202)
            ->assertJsonPath('data.from', '2026-08-01')->assertJsonPath('data.status', 'pending')->assertJsonPath('data.download_url', null);
        Queue::assertPushed(ExportLoanRecap::class);
        $this->postJson(route('pinjaman.rekap.ekspor'), ['jenis' => 'rentang', 'dari' => '2025-01-01', 'sampai' => '2026-09-20'])->assertUnprocessable();
        $this->postJson(route('pinjaman.rekap.ekspor'), ['jenis' => 'rentang', 'dari' => '2026-09-01', 'sampai' => '2026-12-01'])->assertUnprocessable();
        $this->postJson(route('pinjaman.rekap.ekspor'), ['jenis' => 'rentang'])->assertUnprocessable()->assertJsonValidationErrors(['dari', 'sampai']);
        $this->postJson(route('pinjaman.rekap.ekspor'), ['bulan' => '2026-13'])->assertUnprocessable();
        $this->postJson(route('pinjaman.rekap.ekspor'), ['jenis' => 'abc'])->assertUnprocessable();
        $this->postJson(route('pinjaman.rekap.ekspor'), ['bulan' => '2026-09'])->assertStatus(202)->assertJsonPath('data.to', '2026-09-30');
        $this->postJson(route('pinjaman.rekap.ekspor'), [])->assertStatus(202);
        $this->postJson(route('pinjaman.rekap.ekspor'), [])->assertStatus(429); // maks. 3 ekspor belum selesai

        $pending = ReportExport::where('type', 'pinjaman_rekap')->first();
        $this->get(route('pinjaman.rekap.ekspor.unduh', $pending))->assertStatus(409);
        $this->assertSame(0, $pending->fresh()->download_count);
    }

    public function test_only_owner_and_only_loan_recap_exports(): void
    {
        $mine = ReportExport::create(['user_id' => auth()->id(), 'type' => 'pinjaman_rekap', 'format' => 'csv', 'params' => ['from' => '2026-09-01', 'to' => '2026-09-30']]);
        $kas = ReportExport::create(['user_id' => auth()->id(), 'type' => 'kas_rekap', 'format' => 'csv', 'params' => ['from' => '2026-09-01', 'to' => '2026-09-30']]);
        $this->getJson(route('pinjaman.rekap.ekspor.show', $mine))->assertOk();
        $this->getJson(route('pinjaman.rekap.ekspor.show', $kas))->assertNotFound();
        $this->get(route('pinjaman.rekap.ekspor.unduh', $kas))->assertNotFound();

        $this->loginAs('admin');
        $this->getJson(route('pinjaman.rekap.ekspor.show', $mine))->assertNotFound();
        $this->get(route('pinjaman.rekap.ekspor.unduh', $mine))->assertNotFound();
        $this->getJson(route('pinjaman.rekap.ekspor.index'))->assertOk()->assertJsonPath('data', []);

        $this->loginAs('kasir');
        $this->postJson(route('pinjaman.rekap.ekspor'), [])->assertForbidden();
        $this->getJson(route('pinjaman.rekap.ekspor.show', $mine))->assertForbidden();
        $this->get(route('pinjaman.rekap.ekspor.unduh', $mine))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pinjaman.rekap.ekspor.show', $mine))->assertUnauthorized();
        $this->get(route('pinjaman.rekap.ekspor.unduh', $mine))->assertRedirect(route('login'));
    }

    public function test_cell_neutralises_formulas_only_for_text(): void
    {
        $this->assertSame("'=1+1", LoanRecapExport::cell('=1+1'));
        $this->assertSame("'@SUM(A1)", LoanRecapExport::cell('@SUM(A1)'));
        $this->assertSame(-5, LoanRecapExport::cell(-5));
        $this->assertSame('Bu Rina', LoanRecapExport::cell('Bu Rina'));
        $this->assertSame('', LoanRecapExport::cell(''));
    }
}
