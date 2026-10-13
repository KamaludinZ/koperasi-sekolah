<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Show;
use App\Models\Member;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BonRepaymentTest extends TestCase
{
    use RefreshDatabase;

    /** Anggota contoh dengan 2 bon (id genap, kelipatan 3, bukan kelipatan 4 → belum lewat jatuh tempo). */
    private function debtor(int $balance = 100_000): Member
    {
        do {
            $m = Member::factory()->create(['type' => 'guru', 'balance' => $balance]);
        } while ($m->id % 6 !== 0 || $m->id % 4 === 0);

        return $m;
    }

    public function test_mock_bills_match_credit_summary(): void
    {
        foreach (range(1, 24) as $id) {
            $credit = MockData::memberCredit($id, 'siswa');
            $bills = MockData::memberBills($id, 'siswa');
            $this->assertSame($credit['outstanding'], array_sum(array_column($bills, 'remaining')), "id $id");
            $this->assertSame($credit['open_bills'], count($bills), "id $id");
            $this->assertSame($credit['oldest_due_at'], $bills[0]['due_at'] ?? null);
            $this->assertSame($credit['overdue'], $bills[0]['overdue'] ?? false);
        }
    }

    public function test_allocate_pays_oldest_due_first(): void
    {
        $bills = [
            ['id' => 'b', 'due_at' => '2026-10-20', 'remaining' => 5000],
            ['id' => 'a', 'due_at' => '2026-10-10', 'remaining' => 3000],
        ];
        $this->assertSame(['a' => 3000, 'b' => 1000], Show::allocate($bills, 4000));
        $this->assertSame(['a' => 2000], Show::allocate($bills, 2000));
        $this->assertSame(['a' => 3000, 'b' => 5000], Show::allocate($bills, 99000));
    }

    public function test_profile_shows_bon_card(): void
    {
        $m = $this->debtor();
        $bills = MockData::memberBills($m->id, 'guru');
        $this->get('/anggota/'.$m->id)->assertOk()->assertSee('Bon belum lunas')->assertSee($bills[0]['number'])->assertSee('Lunasi bon');

        $clean = Member::factory()->create();
        if ($clean->id % 2 === 0) {
            $clean = Member::factory()->create();
        }
        $this->get('/anggota/'.$clean->id)->assertOk()->assertSee('Tidak ada bon yang belum lunas')->assertDontSee('Lunasi bon');
    }

    public function test_partial_cash_repayment_settles_oldest_bill(): void
    {
        $m = $this->debtor();
        [$first, $second] = MockData::memberBills($m->id, 'guru');
        $total = $first['amount'] + $second['amount'];

        $c = Livewire::test(Show::class, ['member' => $m->id])->call('openRepay')
            ->assertSet('repaying', true)->assertSet('repayAmount', $total)->assertSee('Pelunasan bon')->assertSee('Lunasi semua');

        $c->set('repayAmount', $first['amount'] + 1000)->set('repayNote', 'Dibayar orang tua')->call('saveRepay')->assertHasNoErrors()
            ->assertSet('repaying', false)->assertSee('1 bon lunas');
        $this->assertSame([$second['id']], array_column($c->get('bills'), 'id'));
        $this->assertSame($second['amount'] - 1000, $c->instance()->credit['outstanding']);
        $this->assertSame($second['amount'] - 1000, $c->get('lastRepayment')['remaining']);
        $c->assertSee('dari Rp'.number_format($second['amount'], 0, ',', '.'));

        $c->call('openRepay')->call('saveRepay')->assertSee('Semua bon lunas')->assertSee('Tidak ada bon yang belum lunas');
        $c->call('openRepay')->assertSet('repaying', false);
    }

    public function test_repayment_validation(): void
    {
        $m = $this->debtor(balance: 2000);
        $owed = array_sum(array_column(MockData::memberBills($m->id, 'guru'), 'remaining'));
        $c = Livewire::test(Show::class, ['member' => $m->id])->call('openRepay');

        $c->set('repayAmount', null)->call('saveRepay')->assertHasErrors(['repayAmount' => 'required']);
        $c->set('repayAmount', 100)->call('saveRepay')->assertHasErrors(['repayAmount' => 'min']);
        $c->set('repayAmount', $owed + 500)->call('saveRepay')->assertHasErrors('repayAmount')->assertSee('Melebihi sisa bon');
        $c->set('repayMethod', 'tabungan')->set('repayAmount', 3000)->call('saveRepay')->assertHasErrors('repayAmount')->assertSee('Saldo tabungan hanya Rp2.000.');
        $c->set('repayMethod', 'transfer')->set('repayAmount', 1000)->call('saveRepay')->assertHasErrors('repayMethod');
        $c->set('repayMethod', 'tabungan')->set('repayNote', str_repeat('x', 151))->call('saveRepay')->assertHasErrors('repayNote');
        $c->set('repayNote', '')->call('saveRepay')->assertHasNoErrors();
        $this->assertSame($owed - 1000, $c->instance()->credit['outstanding']);
    }
}
