<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanInstallment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runningLoan(): Loan
    {
        Carbon::setTestNow('2026-05-20 09:00:00');
        $member = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $loan = Loan::factory()->running()->create(['code' => 'PJM-X1', 'member_id' => $member->id, 'principal' => 1000000, 'interest' => 50000,
            'outstanding' => 840000, 'tenor_months' => 5, 'disbursed_at' => '2026-02-01', 'purpose' => 'Biaya sekolah anak']);
        LoanDisbursement::create(['loan_id' => $loan->id, 'disbursed_on' => '2026-02-01', 'amount' => 1000000, 'proof' => 'Kuitansi 017']);
        foreach (range(1, 5) as $n) {
            LoanInstallment::create(['loan_id' => $loan->id, 'sequence' => $n, 'due_date' => Carbon::parse('2026-02-25')->addMonthsNoOverflow($n), 'amount' => 210000,
                'paid_amount' => $n === 1 ? 210000 : 0, 'paid_at' => $n === 1 ? '2026-03-24' : null, 'payment_method' => $n === 1 ? 'potong_gaji' : null,
                'status' => $n === 1 ? 'dibayar' : 'belum']);
        }

        return $loan;
    }

    public function test_detail_shows_loan_schedule_and_summary(): void
    {
        $loan = $this->runningLoan();

        $this->get(route('pinjaman.show', $loan))->assertOk()
            ->assertSee('PJM-X1')->assertSee('Bu Sari')->assertSee('Biaya sekolah anak')->assertSee('Kuitansi 017')
            ->assertSee('Rp840.000')->assertSee('1/5 angsuran')
            ->assertSee('data-installment-status="dibayar"', false)
            ->assertSee('data-installment-status="terlambat"', false) // ke-2 jatuh tempo 25 Apr, lewat dari 20 Mei
            ->assertSee('1 angsuran lewat jatuh tempo.')->assertSee('1 Feb 2026')->assertSee('25 Apr 2026')->assertSee('Rp210.000')
            ->assertSeeInOrder(['data-installment="1"', 'data-installment="2"', 'data-installment="5"'], false);
    }

    public function test_detail_for_new_application_and_link_from_console(): void
    {
        $loan = Loan::factory()->create(['code' => 'PJM-NEW']);
        $this->get('/pinjaman')->assertSee(route('pinjaman.show', $loan), false);
        $this->get(route('pinjaman.show', $loan))->assertOk()->assertSee('Belum dicairkan.')
            ->assertSee('Jadwal angsuran dibuat otomatis saat pinjaman dicairkan.');
    }

    public function test_access_and_missing(): void
    {
        $loan = Loan::factory()->create();
        $this->get('/pinjaman/999999')->assertNotFound();
        $this->loginAs('pengurus');
        $this->get(route('pinjaman.show', $loan))->assertOk();
        $this->loginAs('kasir');
        $this->get(route('pinjaman.show', $loan))->assertForbidden();
    }

    public function test_schedule_table_shows_remaining_totals_next_and_days_late(): void
    {
        $loan = $this->runningLoan(); // hari ini 20 Mei 2026; ke-1 dibayar, ke-2 jatuh tempo 25 Apr (25 hari terlambat)

        $this->get(route('pinjaman.show', $loan))->assertOk()
            ->assertSee('1 dari 5 lunas · 1 lewat jatuh tempo')
            ->assertSee('data-next-installment', false)->assertSeeInOrder(['data-installment="2"', 'data-next-installment', 'berikutnya'], false)
            ->assertSee('25 hari terlambat')
            ->assertSeeInOrder(['data-schedule-total', 'Rp1.050.000', 'Rp210.000', 'Rp840.000'], false);
    }
}
