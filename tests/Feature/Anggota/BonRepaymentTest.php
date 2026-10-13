<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Show;
use App\Models\ActivityLog;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\MemberCredit;
use App\Models\MemberCreditPayment;
use App\Models\SavingsTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BonRepaymentTest extends TestCase
{
    use RefreshDatabase;

    /** Anggota dengan dua bon: 10.000 (jatuh tempo 3 hari) dan 6.000 (10 hari). */
    private function debtor(int $balance = 100_000): array
    {
        $m = Member::factory()->create(['type' => 'guru', 'balance' => $balance]);
        $a = MemberCredit::create(['number' => 'BON-A', 'member_id' => $m->id, 'amount' => 10000, 'due_at' => today()->addDays(3)]);
        $b = MemberCredit::create(['number' => 'BON-B', 'member_id' => $m->id, 'amount' => 6000, 'due_at' => today()->addDays(10)]);

        return [$m, $a, $b];
    }

    public function test_allocate_pays_oldest_due_first(): void
    {
        $bills = [
            ['id' => 2, 'due_at' => '2026-10-20', 'remaining' => 5000],
            ['id' => 1, 'due_at' => '2026-10-10', 'remaining' => 3000],
        ];
        $this->assertSame([1 => 3000, 2 => 1000], Show::allocate($bills, 4000));
        $this->assertSame([1 => 2000], Show::allocate($bills, 2000));
    }

    public function test_profile_shows_bon_card(): void
    {
        [$m] = $this->debtor();
        $this->get('/anggota/'.$m->id)->assertOk()->assertSee('Bon belum lunas')->assertSee('BON-A')->assertSee('Rp16.000')->assertSee('Lunasi bon');

        $clean = Member::factory()->create();
        $this->get('/anggota/'.$clean->id)->assertOk()->assertSee('Tidak ada bon yang belum lunas')->assertDontSee('Lunasi bon');
    }

    public function test_partial_cash_repayment_settles_oldest_bill_and_enters_drawer(): void
    {
        [$m, $a, $b] = $this->debtor();
        $shift = CashShift::factory()->create();

        $c = Livewire::test(Show::class, ['member' => $m->id])->call('openRepay')
            ->assertSet('repaying', true)->assertSet('repayAmount', 16000)->assertSee('Pelunasan bon')->assertSee('Lunasi semua');
        $c->set('repayAmount', 11000)->set('repayNote', 'Dibayar orang tua')->call('saveRepay')->assertHasNoErrors()
            ->assertSet('repaying', false)->assertSee('1 bon lunas')->assertSee('PLN-');

        $this->assertSame(['lunas', 10000], [$a->fresh()->status, $a->fresh()->paid]);
        $this->assertSame(['sebagian', 1000, 5000], [$b->fresh()->status, $b->fresh()->paid, $b->fresh()->remaining()]);
        $this->assertSame([$b->id], array_column($c->get('bills'), 'id'));
        $this->assertSame(5000, $c->instance()->credit['outstanding']);
        $p = MemberCreditPayment::sole();
        $this->assertSame([11000, 'tunai', 'Dibayar orang tua', 2], [$p->amount, $p->method, $p->note, $p->allocations()->count()]);
        $move = CashMovement::sole();
        $this->assertSame(['in', 11000, $shift->id], [$move->type, (int) $move->amount, $move->cash_shift_id]);
        $this->assertStringContainsString($p->number, $move->reason);
        $this->assertTrue(ActivityLog::where('event', 'credit.payment')->exists());

        $c->call('openRepay')->call('saveRepay')->assertSee('Semua bon lunas')->assertSee('Tidak ada bon yang belum lunas');
        $c->call('openRepay')->assertSet('repaying', false);
        $this->assertSame(0, $m->credits()->open()->count());
    }

    public function test_repay_with_savings_deducts_balance(): void
    {
        [$m] = $this->debtor(balance: 20000);
        Livewire::test(Show::class, ['member' => $m->id])->call('openRepay')->set('repayMethod', 'tabungan')->call('saveRepay')->assertHasNoErrors()
            ->assertSee('Rp4.000'); // saldo baru tampil di kartu saldo
        $this->assertSame(4000.0, (float) $m->fresh()->balance);
        $entry = SavingsTransaction::sole();
        $p = MemberCreditPayment::sole();
        $this->assertSame(['tarik', 16000, 'credit_payment', $p->id, $entry->id], [$entry->type, (int) $entry->amount, $entry->source_type, (int) $entry->source_id, $p->savings_transaction_id]);
        $this->assertSame(0, CashMovement::count());
    }

    public function test_repayment_validation(): void
    {
        [$m] = $this->debtor(balance: 2000);
        $c = Livewire::test(Show::class, ['member' => $m->id])->call('openRepay');

        $c->set('repayAmount', null)->call('saveRepay')->assertHasErrors(['repayAmount' => 'required']);
        $c->set('repayAmount', 100)->call('saveRepay')->assertHasErrors(['repayAmount' => 'min']);
        $c->set('repayAmount', 16500)->call('saveRepay')->assertHasErrors('repayAmount')->assertSee('Melebihi sisa bon');
        $c->set('repayMethod', 'tabungan')->set('repayAmount', 3000)->call('saveRepay')->assertHasErrors('repayAmount')->assertSee('Saldo tabungan hanya Rp2.000.');
        $c->set('repayMethod', 'transfer')->set('repayAmount', 1000)->call('saveRepay')->assertHasErrors('repayMethod');
        $c->set('repayMethod', 'tabungan')->set('repayNote', str_repeat('x', 151))->call('saveRepay')->assertHasErrors('repayNote');
        $this->assertSame(0, MemberCreditPayment::count());
        $c->set('repayNote', '')->call('saveRepay')->assertHasNoErrors();
        $this->assertSame(15000, $c->instance()->credit['outstanding']);
    }

    public function test_endpoints(): void
    {
        [$m, $a] = $this->debtor(balance: 0);
        $this->getJson(route('anggota.credits', $m))->assertOk()
            ->assertJsonPath('summary.outstanding', 16000)->assertJsonPath('summary.open_bills', 2)
            ->assertJsonPath('bills.0.number', 'BON-A')->assertJsonCount(0, 'payments');

        $this->postJson(route('anggota.credits.repay', $m), ['amount' => 20000, 'method' => 'tunai'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson(route('anggota.credits.repay', $m), ['amount' => 1000, 'method' => 'tabungan'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson(route('anggota.credits.repay', $m), ['amount' => 1000, 'method' => 'qris'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->postJson(route('anggota.credits.repay', $m), ['amount' => 10000, 'method' => 'tunai', 'note' => 'Lunas BON-A'])->assertCreated()
            ->assertJsonPath('data.amount', 10000)->assertJsonPath('data.allocations.0.number', 'BON-A')->assertJsonPath('summary.outstanding', 6000);
        $this->assertSame('lunas', $a->fresh()->status);
        $this->getJson(route('anggota.credits', $m))->assertJsonCount(1, 'bills')->assertJsonPath('payments.0.note', 'Lunas BON-A');
    }
}
