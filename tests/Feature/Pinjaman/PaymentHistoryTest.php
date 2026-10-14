<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\PaymentHistory;
use App\Livewire\Pinjaman\Show;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-01 09:00:00');
        $m = Member::factory()->teacher()->create(['name' => 'Bu Sari']);
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'member_id' => $m->id, 'status' => 'disetujui', 'principal' => 900000,
            'interest' => 0, 'tenor_months' => 3, 'payment_method' => 'potong_gaji', 'applied_at' => '2026-06-20']);
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]);
        $this->travelTo('2026-10-15 10:00:00');
        $svc = app(LoanPaymentService::class);
        $svc->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-09-30', 'payment_method' => 'potong_gaji', 'reference' => '2026-09']);
        $svc->record($this->loan, ['amount' => 200000, 'paid_at' => '2026-10-02', 'payment_method' => 'transfer', 'reference' => 'BRI 77']);
        $svc->record($this->loan, ['amount' => 100000, 'paid_at' => '2026-10-01', 'payment_method' => 'tunai', 'note' => 'Titip bendahara']);
        // pembayaran dikoreksi (dicoret) — ditandai langsung untuk uji tampilan
        LoanPayment::create(['loan_id' => $this->loan->id, 'paid_at' => '2026-10-03', 'amount' => 50000, 'payment_method' => 'tunai', 'allocations' => [['installment_id' => 0, 'sequence' => 3, 'amount' => 50000]],
            'voided_at' => now(), 'void_reason' => 'Salah input']);
    }

    public function test_loan_history_in_order_with_running_balance(): void
    {
        $h = app(LoanPaymentService::class)->history($this->loan);
        $this->assertSame(['2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03'], array_column($h, 'paid_at')); // urut tanggal bayar
        $this->assertSame([1, 2, 3, null], array_column($h, 'number'));
        $this->assertSame([600000, 500000, 300000, 300000], array_column($h, 'balance_after')); // koreksi tidak mengurangi
        $this->assertSame('Gaji September 2026', $h[0]['reference_label']);
        $this->assertSame([[1], [2], [2]], [$h[0]['sequences'], $h[1]['sequences'], $h[2]['sequences']]); // 1 Okt: sisa ke-2; 2 Okt: awal ke-2

        Livewire::test(Show::class, ['loan' => $this->loan])->assertSee('Riwayat pembayaran')
            ->assertSeeInOrder(['Gaji September 2026', 'Titip bendahara', 'BRI 77', 'Dikoreksi: Salah input'])
            ->assertSeeInOrder(['Rp600.000', 'Rp500.000', 'Rp300.000']);
    }

    public function test_global_history_month_filters_and_totals(): void
    {
        $c = Livewire::test(PaymentHistory::class)->assertSet('month', '2026-10');
        $ids = fn () => collect($c->instance()->payments->items())->pluck('amount')->all();
        $this->assertSame([50000, 200000, 100000], $ids()); // terbaru dulu
        $c->assertSet('totals.amount', 300000)->assertSet('totals.count', 2)
            ->assertSet('totals.by_method', ['potong_gaji' => 0, 'transfer' => 200000, 'tunai' => 100000]);
        $c->set('method', 'transfer');
        $this->assertSame([200000], $ids());
        $c->set('method', '')->set('month', '2026-09');
        $this->assertSame([300000], $ids());
        $c->set('month', '2026-08')->assertSee('Belum ada pembayaran angsuran di bulan ini.');
        $c->set('month', '2026-10')->set('search', 'bu sari');
        $this->assertCount(3, $ids());
        $c->set('search', 'zzz');
        $this->assertSame([], $ids());

        $this->get('/pinjaman/angsuran/riwayat?bulan=rusak')->assertOk(); // bulan tak valid → bulan ini
        $this->get('/pinjaman/angsuran')->assertSee(route('pinjaman.angsuran.riwayat'), false);
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->get('/pinjaman/angsuran/riwayat')->assertForbidden();
    }
}
