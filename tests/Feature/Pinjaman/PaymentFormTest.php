<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Show;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Member;
use App\Services\LoanDisbursementService;
use App\Services\LoanPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentFormTest extends TestCase
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
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-07-01', 'amount' => 900000]); // jatuh tempo 1 Agu, 1 Sep, 1 Okt
        $this->travelTo('2026-08-05 10:00:00');
    }

    public function test_record_payment_from_form(): void
    {
        $user = $this->loginAs('pengurus');
        Livewire::test(Show::class, ['loan' => $this->loan])->assertSee('Catat angsuran')
            ->call('openPayment')->assertSet('payAmount', 300000)->assertSet('payMethod', 'potong_gaji')->assertSet('payDate', '2026-08-05')
            ->set('payAmount', 450000)->assertSeeHtml('data-payment-preview')->assertSee('Angsuran ke-1 (lunas)')->assertSee('Angsuran ke-2 (sebagian)')
            ->set('payNote', 'Potong gaji Agustus')->call('recordPayment')->assertHasNoErrors()->assertSet('paying', false)
            ->assertSee('Angsuran Rp450.000 dicatat untuk angsuran ke-1, 2. Sisa Rp450.000.');

        $loan = $this->loan->fresh('installments');
        $this->assertSame(['berjalan', 450000], [$loan->status, (int) $loan->outstanding]); // ke-1 terlambat tapi kini lunas
        $this->assertSame([300000, 150000, 0], $loan->installments->map(fn ($i) => (int) $i->paid_amount)->all());
        $this->assertSame(['dibayar', 'belum', 'belum'], $loan->installments->pluck('status')->all());
        $p = LoanPayment::sole();
        $this->assertSame([450000, 'potong_gaji', 'Potong gaji Agustus', $user->id], [(int) $p->amount, $p->payment_method, $p->note, $p->user_id]);
        $this->assertSame([['installment_id' => $loan->installments[0]->id, 'sequence' => 1, 'amount' => 300000], ['installment_id' => $loan->installments[1]->id, 'sequence' => 2, 'amount' => 150000]], $p->allocations);
        $log = ActivityLog::where('event', 'pinjaman.angsuran')->sole();
        $this->assertSame([450000, 900000, 450000], [$log->amount, $log->old_values['outstanding'], $log->new_values['outstanding']]);
    }

    public function test_paying_everything_closes_loan(): void
    {
        app(LoanPaymentService::class)->record($this->loan, ['amount' => 900000, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai']);
        $loan = $this->loan->fresh('installments');
        $this->assertSame(['lunas', 0, '2026-08-05'], [$loan->status, (int) $loan->outstanding, $loan->paid_off_at->toDateString()]);
        $this->assertTrue($loan->installments->every(fn ($i) => $i->status === 'dibayar'));
        Livewire::test(Show::class, ['loan' => $loan])->assertDontSee('data-open-payment', false)->call('openPayment')->assertSet('paying', false);
        $this->expectException(ValidationException::class);
        app(LoanPaymentService::class)->record($loan, ['amount' => 1, 'paid_at' => '2026-08-05', 'payment_method' => 'tunai']);
    }

    public function test_overdue_partial_keeps_loan_overdue(): void
    {
        $this->travelTo('2026-09-10 10:00:00'); // ke-1 & ke-2 lewat
        app(LoanPaymentService::class)->record($this->loan, ['amount' => 300000, 'paid_at' => '2026-09-10', 'payment_method' => 'tunai']);
        $loan = $this->loan->fresh('installments');
        $this->assertSame(['menunggak', 600000], [$loan->status, (int) $loan->outstanding]);
        $this->assertSame(['dibayar', 'menunggak', 'belum'], $loan->installments->pluck('status')->all());
    }

    public function test_validation(): void
    {
        Livewire::test(Show::class, ['loan' => $this->loan])->call('openPayment')
            ->set('payAmount', 900001)->set('payDate', '2026-06-30')->set('payMethod', 'kartu')->set('payNote', str_repeat('x', 201))
            ->call('recordPayment')->assertHasErrors(['payAmount' => 'max', 'payDate' => 'after_or_equal', 'payMethod' => 'in', 'payNote' => 'max'])
            ->assertSee('Nominal melebihi sisa pinjaman (Rp900.000).')->assertSee('Tanggal bayar tidak boleh sebelum tanggal cair (1 Jul 2026).')
            ->set('payAmount', 0)->set('payDate', '2026-08-06')->call('recordPayment')->assertHasErrors(['payAmount' => 'min', 'payDate' => 'before_or_equal']);
        $this->assertSame(0, LoanPayment::count());
        $this->assertSame(900000, (int) $this->loan->fresh()->outstanding);
    }

    public function test_allocation_plan(): void
    {
        $inst = $this->loan->installments()->get();
        $this->assertSame([], LoanPaymentService::allocate($inst, 0));
        $this->assertSame([[1, 100, false]], array_map(fn ($r) => [$r['sequence'], $r['amount'], $r['settles']], LoanPaymentService::allocate($inst, 100)));
        $this->assertSame([[1, 300000, true], [2, 300000, true], [3, 300000, true]], array_map(fn ($r) => [$r['sequence'], $r['amount'], $r['settles']], LoanPaymentService::allocate($inst, 5000000)));
    }

    public function test_kasir_forbidden(): void
    {
        $this->loginAs('kasir');
        Livewire::test(Show::class, ['loan' => $this->loan])->call('openPayment')->assertForbidden();
        Livewire::test(Show::class, ['loan' => $this->loan])->call('recordPayment')->assertForbidden();
        $this->assertSame(0, LoanPayment::count());
    }
}
