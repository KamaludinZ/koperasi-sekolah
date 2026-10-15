<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\AuditTrail;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditTrailPageTest extends TestCase
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
        ActivityLog::query()->create(['event' => 'sale.created', 'category' => 'transaksi', 'description' => 'Penjualan LAIN', 'actor_name' => 'Kasir']);
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    public function test_lists_only_loan_events_newest_first_with_labels(): void
    {
        $this->get(route('pinjaman.audit'))->assertOk()->assertSee('Jejak Audit Pinjaman')
            ->assertSeeInOrder(['data-audit="pinjaman.ditolak"', 'data-audit="pinjaman.angsuran_koreksi"', 'data-audit="pinjaman.angsuran"', 'data-audit="pinjaman.dicairkan"'], false)
            ->assertSee('Angsuran dikoreksi')->assertSee('Alasan: Nominal salah ketik')->assertDontSee('Penjualan LAIN')
            ->assertSee(route('pinjaman.audit'), false); // tab di navigasi
    }

    public function test_group_filters_search_actor_loan_and_counts(): void
    {
        $c = Livewire::test(AuditTrail::class);
        $counts = $c->get('counts');
        $this->assertSame([1, 1, 2, 0, 0, 4, 1], [$counts['pengajuan'], $counts['pencairan'], $counts['angsuran'], $counts['tunggakan'], $counts['ekspor'], $counts['semua'], $counts['perhatian']]);

        $c->call('setGroup', 'angsuran')->assertSeeHtml('data-audit="pinjaman.angsuran"')->assertDontSeeHtml('data-audit="pinjaman.ditolak"')
            ->call('setGroup', 'perhatian')->assertSeeHtml('data-audit="pinjaman.angsuran_koreksi"')->assertDontSeeHtml('data-audit="pinjaman.angsuran"')
            ->call('setGroup', 'perhatian')->assertSet('group', '')
            ->set('search', 'budi')->assertSeeHtml('data-audit="pinjaman.ditolak"')->assertDontSeeHtml('data-audit="pinjaman.dicairkan"')
            ->set('search', '')->set('loanId', $this->loan->id)->assertSeeHtml('data-loan-filter')->assertDontSeeHtml('data-audit="pinjaman.ditolak"')
            ->set('loanId', 0)->set('actor', 'Tidak Ada')->assertSeeHtml('data-empty="audit-pinjaman"')
            ->set('group', 'ngawur')->assertSet('group', '')->set('period', 'x')->assertSet('period', '30-hari')
            ->call('resetFilters')->assertSet('actor', '');

        $this->travelTo('2026-12-30 10:00:00');
        Livewire::test(AuditTrail::class)->assertSeeHtml('data-empty="audit-pinjaman"')->set('period', 'semua')->assertSeeHtml('data-audit="pinjaman.dicairkan"');
    }

    public function test_detail_panel_shows_diff_reason_and_rejects_other_categories(): void
    {
        $fix = ActivityLog::where('event', 'pinjaman.angsuran_koreksi')->sole();
        $other = ActivityLog::where('category', 'transaksi')->sole();
        Livewire::test(AuditTrail::class)->call('show', $fix->id)->assertSet('selectedId', $fix->id)
            ->assertSeeHtml('data-audit-diff')->assertSee('outstanding')->assertSee('Nominal salah ketik')->assertSee(route('pinjaman.show', $this->loan), false)
            ->call('show', $other->id)->assertSet('selectedId', null)->assertDontSee('Penjualan LAIN')
            ->call('show', $fix->id)->call('closeDetail')->assertSet('selectedId', null);
    }

    public function test_ledger_view_lists_disbursements_and_payments_including_corrected(): void
    {
        $c = Livewire::test(AuditTrail::class)->call('setView', 'transaksi')->assertSet('view', 'transaksi')
            ->assertSeeHtmlInOrder(['data-ledger="pengganti"', 'data-ledger="dibatalkan"', 'data-ledger="pencairan"'])
            ->assertSeeHtml('data-void-reason')->assertSeeText('Nominal salah ketik')->assertSeeText('menggantikan pembayaran')
            ->assertSeeText('−Rp300.000')->assertSeeText('+Rp90.000')->assertSeeText('angsuran ke-1');
        $tot = \App\Services\LoanLedgerQuery::totals($c->get('ledgerAll'));
        $this->assertSame(['disbursed' => 300000, 'collected' => 90000, 'voided' => 100000, 'voided_count' => 1], $tot);

        $c->set('ledgerType', 'pencairan')->assertSeeHtml('data-ledger="pencairan"')->assertDontSeeHtml('data-ledger="pengganti"')
            ->set('ledgerType', 'koreksi')->assertSeeHtml('data-ledger="dibatalkan"')->assertSeeHtml('data-ledger="pengganti"')->assertDontSeeHtml('data-ledger="pencairan"')
            ->set('ledgerType', 'angsuran')->assertSeeHtml('data-empty="riwayat-transaksi"') // satu-satunya angsuran sudah dikoreksi
            ->set('ledgerType', 'x')->assertSet('ledgerType', '')
            ->set('search', 'budi')->assertSeeHtml('data-empty="riwayat-transaksi"')
            ->set('search', 'rina')->assertSeeHtml('data-ledger="pencairan"')
            ->call('setView', 'ngawur')->assertSet('view', 'jejak');

        $this->get(route('pinjaman.audit', ['tampil' => 'transaksi', 'pinjaman' => $this->loan->id]))->assertOk()->assertSeeHtml('data-ledger="pencairan"');
        $this->travelTo('2027-03-01 10:00:00');
        Livewire::test(AuditTrail::class)->call('setView', 'transaksi')->assertSeeHtml('data-empty="riwayat-transaksi"'); // 30 hari terakhir
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->get(route('pinjaman.audit'))->assertForbidden();
        auth()->logout();
        $this->get(route('pinjaman.audit'))->assertRedirect(route('login'));
    }
}
