<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Member;
use App\Models\Transaction;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->loginAs('kasir@koperasi.test');
    }

    private function cart(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Cart::class)->call('add', $this->pid('ATK001'))->call('add', $this->pid('ATK001'))->call('openPayment'); // Rp8.000
    }

    public function test_methods_listed_and_member_search(): void
    {
        $this->assertSame(['tunai', 'qris', 'tabungan', 'bon'], array_keys(Cart::PAYMENT_METHODS));
        Member::factory()->create(['name' => 'Andi Pratama', 'member_code' => 'S-2026-001', 'balance' => 20000]);
        Member::factory()->create(['name' => 'Andi Nonaktif', 'is_active' => false]);

        $c = $this->cart()->assertSee('Saldo tabungan')->assertSee('Bon (bayar nanti)')->set('memberSearch', 'andi');
        $this->assertSame(['Andi Pratama'], array_column($c->instance()->memberResults, 'name'));
        $c->call('selectMember', $c->instance()->memberResults[0]['id'])->assertSet('memberSearch', '')
            ->set('paymentMethod', 'tabungan')->assertSee('S-2026-001')->assertSee('Rp20.000');
        $c->call('clearMember')->assertSet('memberId', null);
    }

    public function test_tabungan_requires_member_and_enough_balance(): void
    {
        $c = $this->cart()->set('paymentMethod', 'tabungan')->call('pay')->assertHasErrors('memberId');
        $poor = Member::factory()->create(['balance' => 5000]);
        $c->call('selectMember', $poor->id)->call('pay')->assertHasErrors('memberId')->assertSee('Saldo tabungan kurang Rp3.000.');

        $rich = Member::factory()->create(['balance' => 50000]);
        $c->call('selectMember', $rich->id)->call('pay')->assertHasNoErrors('memberId')->assertHasErrors('paymentMethod');
        $this->assertSame(0, Transaction::count()); // tahap tampilan: belum disimpan
        $this->assertSame(0, Cart::shortfall('tabungan', ['balance' => 50000], 8000));
    }

    public function test_bon_respects_limit_and_overdue(): void
    {
        $c = $this->cart()->set('paymentMethod', 'bon');
        $odd = Member::factory()->create(['type' => 'siswa']);
        if ($odd->id % 2 === 0) {
            $odd = Member::factory()->create(['type' => 'siswa']);
        }
        $this->assertSame(['limit' => 50_000, 'outstanding' => 0], array_intersect_key(MockData::memberCredit($odd->id, 'siswa'), ['limit' => 1, 'outstanding' => 1]));
        $c->call('selectMember', $odd->id)->assertSee('Jatuh tempo bon')->set('bonDays', 30)->call('pay')->assertHasErrors('paymentMethod');

        $credit = ['available' => 5_000, 'overdue' => false];
        $this->assertSame(3_000, Cart::shortfall('bon', ['credit' => $credit, 'balance' => 0], 8_000));

        do {
            $overdue = Member::factory()->create(['type' => 'guru']);
        } while ($overdue->id % 4 !== 0);
        $c->call('selectMember', $overdue->id)->call('pay')->assertHasErrors('memberId')->assertSee('lewat jatuh tempo');
    }

    public function test_cash_and_qris_still_complete(): void
    {
        $this->cart()->set('paymentMethod', 'qris')->call('pay')->assertHasNoErrors();
        $this->assertSame('qris', Transaction::sole()->payment_method);
    }
}
