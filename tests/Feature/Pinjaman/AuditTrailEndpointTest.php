<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrailEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-01 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-A', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 300000, 'interest' => 0,
            'tenor_months' => 3, 'payment_method' => 'tunai', 'applied_at' => '2026-09-25']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-10-01', 'amount' => 300000], auth()->id());
        $this->travelTo('2026-10-10 10:00:00');
        $svc = app(LoanPaymentService::class);
        $p = $svc->record($this->loan, ['amount' => 100000, 'paid_at' => '2026-10-10', 'payment_method' => 'tunai'], auth()->id());
        $svc->correct($p, 'Nominal salah ketik', ['amount' => 90000, 'paid_at' => '2026-10-10', 'payment_method' => 'tunai'], auth()->id());
        $other = Loan::factory()->create(['code' => 'PJM-B', 'member_id' => Member::factory()->teacher()->create(['name' => 'Pak Budi'])->id]);
        app(LoanApplicationService::class)->decide($other, 'ditolak', 'Belum memenuhi syarat', auth()->id());
        ActivityLog::create(['event' => 'sale.created', 'category' => 'transaksi', 'description' => 'Penjualan LAIN']);
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    public function test_chronology_newest_first_with_chain_links_and_counts(): void
    {
        $res = $this->getJson(route('pinjaman.audit.data'))->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonPath('counts.semua', 4)->assertJsonPath('counts.perhatian', 1)
            ->assertJsonPath('filters.from', '2026-09-11');
        $this->assertSame(['pinjaman.ditolak', 'pinjaman.angsuran_koreksi', 'pinjaman.angsuran', 'pinjaman.dicairkan'], array_column($res->json('data'), 'event'));
        $fix = $res->json('data.1');
        $this->assertSame(['Angsuran dikoreksi', 'angsuran', true, 'Nominal salah ketik'], [$fix['label'], $fix['group'], $fix['notable'], $fix['reason']]);
        $this->assertSame(['id' => $this->loan->id, 'label' => $fix['loan']['label']], $fix['loan']);
        $this->assertSame(DB::table('loan_audit_chain')->where('source_type', 'activity')->where('source_id', $fix['id'])->value('hmac'), $fix['chain']['hmac']);
        $this->assertSame(64, strlen($fix['chain']['prev_hmac']));
        $this->assertStringNotContainsString('Penjualan LAIN', $res->getContent());
    }

    public function test_filters_and_pagination(): void
    {
        $events = fn ($p) => array_column($this->getJson(route('pinjaman.audit.data', $p))->assertOk()->json('data'), 'event');
        $this->assertSame(['pinjaman.angsuran_koreksi', 'pinjaman.angsuran'], $events(['kelompok' => 'angsuran']));
        $this->assertSame(['pinjaman.angsuran_koreksi'], $events(['kelompok' => 'perhatian']));
        $this->assertSame(['pinjaman.dicairkan'], $events(['peristiwa' => 'pinjaman.dicairkan']));
        $this->assertSame(['pinjaman.ditolak'], $events(['q' => 'budi']));
        $this->assertSame(3, count($events(['pinjaman' => $this->loan->id])));
        $this->assertSame([], $events(['petugas' => 'Orang Lain']));
        $this->assertSame(['pinjaman.dicairkan'], $events(['dari' => '2026-10-01', 'sampai' => '2026-10-01']));
        $this->assertSame([], $events(['periode' => 'hari-ini', 'dari' => '2025-01-01', 'sampai' => '2025-01-31']));
        $this->getJson(route('pinjaman.audit.data', ['per_page' => 3, 'page' => 2]))->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');

        foreach ([['kelompok' => 'x'], ['peristiwa' => 'sale.created'], ['periode' => 'kemarin'], ['dari' => '2026-10-05', 'sampai' => '2026-10-01'],
            ['per_page' => 201], ['pinjaman' => 0], ['q' => str_repeat('a', 101)], ['dari' => '01/10/2026']] as $p) {
            $this->getJson(route('pinjaman.audit.data', $p))->assertUnprocessable();
        }
    }

    public function test_ledger_endpoint(): void
    {
        $res = $this->getJson(route('pinjaman.audit.transaksi'))->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('totals.disbursed', 300000)->assertJsonPath('totals.collected', 90000)->assertJsonPath('totals.voided_count', 1);
        $this->assertSame(['pengganti', 'dibatalkan', 'pencairan'], array_column($res->json('data'), 'type'));
        $this->assertSame('Nominal salah ketik', $res->json('data.1.void.reason'));
        $this->assertSame(['pencairan'], array_column($this->getJson(route('pinjaman.audit.transaksi', ['jenis' => 'pencairan']))->json('data'), 'type'));
        $this->getJson(route('pinjaman.audit.transaksi', ['jenis' => 'semua']))->assertUnprocessable();
    }

    public function test_csv_export_endpoint_signed_and_audited(): void
    {
        $res = $this->get(route('pinjaman.audit.ekspor', ['kelompok' => 'angsuran']))->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Audit-Rows', '2')->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $res->getContent();
        $this->assertStringContainsString('attachment; filename="jejak-audit-pinjaman-20261010-100000.csv"', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"HMAC rantai"', $csv);
        $this->assertStringContainsString(DB::table('loan_audit_chain')->where('source_type', 'activity')
            ->where('source_id', ActivityLog::where('event', 'pinjaman.angsuran_koreksi')->value('id'))->value('hmac'), $csv);
        $this->assertStringNotContainsString('pinjaman.ditolak', $csv);

        $sig = $res->headers->get('X-Audit-Signature');
        $this->assertTrue(\App\Services\LoanAuditExport::signatureMatches($csv, $sig));
        $this->assertFalse(\App\Services\LoanAuditExport::signatureMatches(str_replace('Nominal salah ketik', 'Nominal benar', $csv), $sig));
        $log = ActivityLog::where('event', 'pinjaman.audit_diekspor')->sole();
        $this->assertSame([$sig, 2, 'jejak', 'angsuran'], [$log->properties['signature'], $log->properties['rows'], $log->properties['view'], $log->properties['kelompok']]);

        $ledger = $this->get(route('pinjaman.audit.ekspor', ['tampil' => 'transaksi']))->assertOk()->assertHeader('X-Audit-Rows', '3');
        $this->assertStringContainsString(';Dibatalkan;', $ledger->getContent());
        $this->assertStringContainsString('riwayat-pencairan-angsuran-', $ledger->headers->get('Content-Disposition'));

        foreach ([['tampil' => 'semua'], ['kelompok' => 'x'], ['jenis' => 'x'], ['dari' => 'kemarin']] as $p) {
            $this->getJson(route('pinjaman.audit.ekspor', $p))->assertUnprocessable();
        }
        $this->loginAs('kasir');
        $this->get(route('pinjaman.audit.ekspor'))->assertForbidden();
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.audit.data'))->assertForbidden();
        $this->getJson(route('pinjaman.audit.transaksi'))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pinjaman.audit.data'))->assertUnauthorized();
    }
}
