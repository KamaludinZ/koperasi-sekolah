<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Recap;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use App\Services\LoanRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecapPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-03 09:00:00');
        $this->loginAs('pengurus');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        $guru = Member::factory()->teacher()->create(['name' => 'Pak Dedi']);
        // Agustus: 1 cair 900rb (bunga 90rb, 3 bln), 1 ditolak
        $a = Loan::factory()->create(['code' => 'PJM-A', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 900000, 'interest' => 90000,
            'tenor_months' => 3, 'payment_method' => 'potong_gaji', 'applied_at' => '2026-08-01', 'decided_at' => '2026-08-02 10:00:00']);
        app(LoanDisbursementService::class)->disburse($a, ['disbursed_on' => '2026-08-03', 'amount' => 900000]);
        $no = Loan::factory()->create(['code' => 'PJM-NO', 'member_id' => $guru->id, 'applied_at' => '2026-08-02', 'principal' => 500000]);
        app(LoanApplicationService::class)->decide($no, 'ditolak', 'Belum memenuhi syarat');

        // September: angsuran ke-1 330rb transfer + koreksi (yang batal tidak dihitung), Oktober: ke-2 potong gaji
        $this->travelTo('2026-09-03 10:00:00');
        $svc = app(LoanPaymentService::class);
        $wrong = $svc->record($a, ['amount' => 300000, 'paid_at' => '2026-09-03', 'payment_method' => 'transfer', 'reference' => 'TRX-X'], auth()->id());
        $svc->correct($wrong, 'Nominal salah ketik', ['amount' => 330000, 'paid_at' => '2026-09-03', 'payment_method' => 'transfer', 'reference' => 'TRX-1'], auth()->id());
        $this->travelTo('2026-10-03 10:00:00');
        $svc->record($a->fresh(), ['amount' => 330000, 'paid_at' => '2026-10-03', 'payment_method' => 'potong_gaji', 'reference' => '2026-10'], auth()->id());
        $this->travelTo('2026-10-15 10:00:00');
        $this->flushSession();
        $this->loginAs('pengurus');
    }

    public function test_monthly_recap_defaults_to_current_month(): void
    {
        $this->get(route('pinjaman.rekap'))->assertOk()->assertSee('Rekap Pinjaman')->assertSee('Oktober 2026')
            ->assertSeeText('Rp330.000')->assertSeeText('pokok Rp300.000 · bunga Rp30.000');

        Livewire::test(Recap::class)->set('month', '2026-08')->assertSee('Agustus 2026')
            ->assertSeeText('Rp900.000')->assertSeeText('1 pinjaman')->assertSeeText('1 disetujui · 1 ditolak');
    }

    public function test_service_numbers_exclude_voided_payments_and_split_interest(): void
    {
        [$from, $to] = LoanRecapService::period('bulan', '2026-09');
        $r = app(LoanRecapService::class)->recap($from, $to);
        $this->assertSame(['count' => 1, 'amount' => 330000, 'principal' => 300000, 'interest' => 30000], array_intersect_key($r['collected'], array_flip(['count', 'amount', 'principal', 'interest'])));
        $this->assertSame([0, 330000, 0], array_column($r['collected']['by_method'], 'amount'));
        $this->assertSame(['count' => 0, 'amount' => 0], $r['disbursed']);
        $this->assertSame([], $r['months']);

        [$from, $to] = LoanRecapService::period('tahun', '2026');
        $y = app(LoanRecapService::class)->recap($from, $to);
        $this->assertSame(660000, $y['collected']['amount']);
        $this->assertSame([330000, 330000, 0], array_column($y['collected']['by_method'], 'amount')); // potong gaji, transfer, tunai
        $this->assertCount(12, $y['months']);
        $this->assertSame(900000, collect($y['months'])->firstWhere('month', '2026-08')['disbursed']);
        $this->assertSame(330000, collect($y['months'])->firstWhere('month', '2026-10')['collected']);
        $this->assertSame(['count' => 2, 'amount' => 1400000, 'approved' => 1, 'rejected' => 1], $y['applications']);
        $this->assertSame(['active' => 1, 'outstanding' => 330000, 'principal_outstanding' => 300000, 'interest_outstanding' => 30000,
            'overdue' => 0, 'overdue_outstanding' => 0, 'paid_off' => 0, 'disbursed_total' => 900000, 'disbursed_count' => 1,
            'collected_total' => 660000, 'collection_rate' => 66.7], $y['portfolio']);
    }

    public function test_summary_cards_independent_of_period(): void
    {
        foreach (['2026-10', '2025-01'] as $month) {
            Livewire::test(Recap::class)->set('month', $month)
                ->assertSeeHtml('data-summary="aktif"')->assertSeeText('1 pinjaman')->assertSeeText('Sisa Rp330.000')
                ->assertSeeText('Pokok Rp300.000 · bunga Rp30.000')
                ->assertSeeHtml('data-summary="tercairkan"')->assertSeeText('Rp900.000')->assertSeeText('1 pencairan · 0 pinjaman lunas')
                ->assertSeeHtml('data-summary="kembali"')->assertSeeText('Rp660.000')->assertSeeText('66,7% dari pokok + bunga');
        }

        // tunggakan muncul di kartu ketika angsuran ke-3 (3 Nov) lewat
        $this->travelTo('2026-11-10 10:00:00');
        $this->assertSame(1, app(LoanRecapService::class)->portfolio()['overdue']);
        Livewire::test(Recap::class)->assertSeeText('1 menunggak (Rp330.000)');
    }

    public function test_period_navigation_and_validation(): void
    {
        Livewire::test(Recap::class)
            ->call('shift', 1)->assertSet('month', '')                           // tidak bisa melewati bulan berjalan
            ->call('shift', -1)->assertSet('month', '2026-09')->assertSee('September 2026')
            ->set('month', '2027-01')->assertSet('month', '')                   // masa depan ditolak
            ->set('month', 'abc')->assertSet('month', '')
            ->call('setType', 'tahun')->assertSee('Tahun 2026')->assertSeeHtml('data-month="2026-08"')
            ->call('shift', -1)->assertSet('year', '2025')->assertSeeHtml('data-empty="rekap"')->assertSee('Belum ada kegiatan pinjaman pada Tahun 2025')
            ->call('setType', 'x')->assertSet('type', 'bulan');

        $this->get(route('pinjaman.rekap', ['jenis' => 'tahun', 'tahun' => '2026']))->assertOk()->assertSee('Tahun 2026');
        $this->get(route('pinjaman.rekap', ['bulan' => '2026-13']))->assertOk()->assertSee('Oktober 2026');
    }

    public function test_nav_and_access(): void
    {
        $this->get(route('pinjaman'))->assertSee(route('pinjaman.rekap'), false);
        $this->loginAs('kasir');
        $this->get(route('pinjaman.rekap'))->assertForbidden();
    }
}
