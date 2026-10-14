<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Loans;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoansPageTest extends TestCase
{
    use RefreshDatabase;

    private Member $sari;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $this->sari = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $other = Member::factory()->teacher()->create(['name' => 'Pak Lain']);

        $run = Loan::factory()->create(['code' => 'PJM-RUN', 'member_id' => $this->sari->id, 'status' => 'disetujui', 'principal' => 900000, 'interest' => 0, 'tenor_months' => 3, 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($run, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        Loan::factory()->create(['code' => 'PJM-NEW', 'member_id' => $this->sari->id, 'applied_at' => '2026-06-30']);
        Loan::factory()->create(['code' => 'PJM-NO', 'member_id' => $this->sari->id, 'status' => 'ditolak', 'decision_note' => 'Masih ada pinjaman berjalan']);
        Loan::factory()->create(['code' => 'PJM-OTHER', 'member_id' => $other->id, 'principal' => 7777000]);

        $this->travelTo('2026-08-10 10:00:00');
        app(LoanPaymentService::class)->record($run, ['amount' => 300000, 'paid_at' => '2026-08-01', 'payment_method' => 'tunai']);
        auth()->logout();
        $this->actingAsMember($this->sari);
    }

    public function test_member_sees_only_own_loans_with_status(): void
    {
        $this->get('/portal/pinjaman')->assertOk()->assertSee('Pinjaman saya')
            ->assertSeeInOrder(['data-my-loan="PJM-RUN"', 'data-my-loan="PJM-NEW"', 'data-my-loan="PJM-NO"'], false)
            ->assertDontSee('PJM-OTHER')->assertDontSee('Rp7.777.000')
            ->assertSeeHtml('data-total-outstanding>Rp600.000')
            ->assertSee('33% terbayar')->assertSee('Angsuran ke-2: Rp300.000 · jatuh tempo 1 Sep 2026')
            ->assertSee('sedang menunggu keputusan pengurus')->assertSee('Pengajuan tidak disetujui: Masih ada pinjaman berjalan')
            ->assertSee(route('portal.pinjaman'), false); // ada di navigasi portal
    }

    public function test_overdue_and_paid_off_messages(): void
    {
        $this->travelTo('2026-09-05 10:00:00');
        Livewire::test(Loans::class)->assertSee('1 angsuran lewat jatuh tempo, total Rp300.000 — segera hubungi pengurus.')
            ->assertSeeHtml('data-late-amount>Tunggakan Rp300.000');

        app(LoanPaymentService::class)->record(Loan::where('code', 'PJM-RUN')->first(), ['amount' => 600000, 'paid_at' => '2026-09-05', 'payment_method' => 'tunai']);
        Livewire::test(Loans::class)->assertSee('Lunas 5 Sep 2026. Terima kasih!')->assertDontSeeHtml('data-total-outstanding');
    }

    public function test_remaining_breakdown_and_nearest_due(): void
    {
        $second = Loan::factory()->create(['code' => 'PJM-TWO', 'member_id' => $this->sari->id, 'status' => 'disetujui', 'principal' => 1000000, 'interest' => 200000, 'tenor_months' => 4, 'applied_at' => '2026-07-20']);
        app(LoanDisbursementService::class)->disburse($second, ['disbursed_on' => '2026-07-25', 'amount' => 1000000]);
        app(LoanPaymentService::class)->record($second, ['amount' => 300000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai']);

        // PJM-RUN ke-2 jatuh tempo 1 Sep, PJM-TWO ke-2 jatuh tempo 25 Sep → terdekat PJM-RUN
        $this->get('/portal/pinjaman')->assertOk()
            ->assertSeeHtml('data-total-outstanding>Rp1.500.000')
            ->assertSee('Angsuran terdekat:')->assertSee('PJM-RUN ke-2 · jatuh tempo 1 Sep 2026')
            ->assertDontSeeHtml('data-late-amount')
            ->assertSeeInOrder(['data-loan-balance="PJM-TWO"', 'Sisa pokok', 'Rp750.000', 'Sisa bunga', 'Rp150.000', 'Angsuran lunas', '1 dari 4'], false)
            ->assertSeeInOrder(['data-loan-balance="PJM-RUN"', 'Rp600.000', 'Rp0', '1 dari 3'], false)
            ->assertDontSeeHtml('data-loan-balance="PJM-NEW"');
    }

    public function test_payment_history_with_method_hides_corrected_and_internal_data(): void
    {
        $run = Loan::where('code', 'PJM-RUN')->first();
        $svc = app(LoanPaymentService::class);
        $wrong = $svc->record($run, ['amount' => 100000, 'paid_at' => '2026-08-05', 'payment_method' => 'transfer', 'reference' => 'TRX-SALAH', 'note' => 'CATATAN-INTERNAL']);
        $svc->correct($wrong, 'Nominal salah ketik', ['amount' => 150000, 'paid_at' => '2026-08-05', 'payment_method' => 'transfer', 'reference' => 'TRX-889']);
        $svc->record($run, ['amount' => 150000, 'paid_at' => '2026-08-09', 'payment_method' => 'potong_gaji', 'reference' => '2026-08']);

        $this->get('/portal/pinjaman')->assertOk()
            ->assertSee('Riwayat angsuran (3)')
            ->assertSeeInOrder(['data-loan-payments="PJM-RUN"',
                'Rp150.000', '9 Agt 2026', 'Potong gaji', 'Gaji Agustus 2026', 'angsuran ke-2', 'Rp300.000',
                'Rp150.000', '5 Agt 2026', 'Transfer', 'TRX-889', 'Rp450.000',
                'Rp300.000', '1 Agt 2026', 'Tunai', 'angsuran ke-1', 'Rp600.000'], false)
            ->assertDontSee('TRX-SALAH')->assertDontSee('CATATAN-INTERNAL')->assertDontSee('Nominal salah ketik')
            ->assertDontSeeHtml('data-loan-payments="PJM-NEW"');
    }

    public function test_filter_tabs_with_counts_and_url(): void
    {
        Livewire::test(Loans::class)
            ->assertSeeHtml('data-loan-tab="berjalan"')->assertSeeInOrder(['Semua', '3', 'Berjalan', '1', 'Pengajuan', '1', 'Selesai', '1'])
            ->call('setTab', 'berjalan')->assertSet('tab', 'berjalan')
            ->assertSeeHtml('data-my-loan="PJM-RUN"')->assertDontSeeHtml('data-my-loan="PJM-NEW"')->assertDontSeeHtml('data-my-loan="PJM-NO"')
            ->assertSeeHtml('data-total-outstanding>Rp600.000') // ringkasan tetap dari semua pinjaman
            ->call('setTab', 'pengajuan')->assertSeeHtml('data-my-loan="PJM-NEW"')->assertDontSeeHtml('data-my-loan="PJM-RUN"')
            ->call('setTab', 'selesai')->assertSeeHtml('data-my-loan="PJM-NO"')->assertDontSeeHtml('data-my-loan="PJM-NEW"')
            ->call('setTab', 'ngawur')->assertSet('tab', 'semua')->assertSeeHtml('data-my-loan="PJM-NO"');

        $this->get('/portal/pinjaman?tampil=selesai')->assertOk()->assertSeeHtml('data-my-loan="PJM-NO"')->assertDontSeeHtml('data-my-loan="PJM-RUN"');
        $this->get('/portal/pinjaman?tampil=%3Cscript%3E')->assertOk()->assertSeeHtml('data-my-loan="PJM-RUN"')->assertDontSee('<script>', false);
    }

    public function test_empty_tab_message(): void
    {
        Loan::where('code', 'PJM-NO')->delete();
        Livewire::test(Loans::class)->call('setTab', 'selesai')
            ->assertSeeHtml('data-empty="daftar-pinjaman"')->assertSee('Belum ada pinjaman yang selesai.');
    }

    public function test_empty_state(): void
    {
        $fresh = Member::factory()->create();
        $this->actingAsMember($fresh);
        $this->get('/portal/pinjaman')->assertOk()->assertSeeHtml('data-empty="pinjaman-saya"')->assertDontSee('PJM-RUN');
    }

    public function test_requires_member_login(): void
    {
        auth('member')->logout();
        $this->get('/portal/pinjaman')->assertRedirect(route('portal.login'));
        $this->loginAs('admin'); // sesi petugas bukan sesi anggota
        $this->get('/portal/pinjaman')->assertRedirect(route('portal.login'));
    }
}
