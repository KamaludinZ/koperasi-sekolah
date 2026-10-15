<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Show;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanChangeLogTest extends TestCase
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
        $this->travelTo('2026-10-11 10:00:00');
        $svc->correct($p, 'Nominal salah ketik', ['amount' => 90000, 'paid_at' => '2026-10-10', 'payment_method' => 'tunai'], auth()->id());
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    public function test_detail_shows_merged_change_log_newest_first(): void
    {
        $c = Livewire::test(Show::class, ['loan' => $this->loan])->assertSeeHtml('data-change-log');
        $log = $c->get('changeLog');
        // audit: dicairkan, angsuran, koreksi (3) + angsuran: ke-1 pembayaran, ke-1 koreksi (2)
        $this->assertSame(5, $log['total']);
        // dua teratas = koreksi 11 Okt (jejak pinjaman + perubahan angsuran, detik yang sama → urutan antar keduanya bebas)
        $top = array_column(array_slice($log['rows'], 0, 2), 'kind');
        sort($top);
        $this->assertSame(['angsuran', 'pinjaman'], $top);
        $this->assertSame('Dana dicairkan', end($log['rows'])['title']); // terlama di bawah
        $this->assertSame('Angsuran dikoreksi', collect($log['rows'])->firstWhere('kind', 'pinjaman')['title']);
        $this->assertSame('Angsuran ke-1 · Koreksi', collect($log['rows'])->firstWhere('kind', 'angsuran')['title']);
        $c->assertSeeText('Alasan: Nominal salah ketik')->assertSeeText('Dibayar: Rp100.000 → Rp90.000')
            ->assertSeeText('Dana dicairkan')->assertSee(route('pinjaman.audit', ['pinjaman' => $this->loan->id]), false)
            ->assertDontSeeHtml('data-more-changes');
    }

    public function test_kind_filter_and_more(): void
    {
        Livewire::test(Show::class, ['loan' => $this->loan])
            ->call('setChangeKind', 'angsuran')->assertSeeHtml('data-change="angsuran"')->assertDontSeeHtml('data-change="pinjaman"')
            ->call('setChangeKind', 'pinjaman')->assertSeeHtml('data-change="pinjaman"')->assertDontSeeHtml('data-change="angsuran"')
            ->call('setChangeKind', 'ngawur')->assertSet('changeKind', 'semua')
            ->set('changeLimit', 2)->assertSeeHtml('data-more-changes')->call('moreChanges')->assertSet('changeLimit', 22)->assertDontSeeHtml('data-more-changes')
            ->set('changeLimit', 99999)->assertOk();
    }

    public function test_fresh_application_shows_empty_or_only_its_own_events(): void
    {
        $other = Loan::factory()->create(['code' => 'PJM-B']);
        Livewire::test(Show::class, ['loan' => $other])->assertSeeHtml('data-empty="riwayat-perubahan"')->assertDontSee('Nominal salah ketik');
    }
}
