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
        Livewire::test(Loans::class)->assertSee('1 angsuran lewat jatuh tempo — segera hubungi pengurus.');

        app(LoanPaymentService::class)->record(Loan::where('code', 'PJM-RUN')->first(), ['amount' => 600000, 'paid_at' => '2026-09-05', 'payment_method' => 'tunai']);
        Livewire::test(Loans::class)->assertSee('Lunas 5 Sep 2026. Terima kasih!')->assertDontSeeHtml('data-total-outstanding');
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
