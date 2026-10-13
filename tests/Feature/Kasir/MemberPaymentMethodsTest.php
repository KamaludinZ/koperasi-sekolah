<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Member;
use App\Models\MemberCredit;
use App\Models\Transaction;
use App\Services\MemberCreditService;
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
        $c->call('selectMember', $rich->id)->call('pay')->assertHasNoErrors('memberId')->assertHasErrors('savingsConsent');
        $c->set('savingsConsent', true)->call('pay')->assertHasNoErrors()->assertSee('Sisa saldo')->assertSee('Rp42.000');
        $tx = Transaction::sole();
        $this->assertSame(['tabungan', $rich->id, 8000, 0], [$tx->payment_method, $tx->member_id, (int) $tx->paid, (int) $tx->change]);
        $this->assertSame(42000.0, (float) $rich->fresh()->balance);
        $this->assertNull($c->get('memberId'));
        $this->assertSame(0, Cart::shortfall('tabungan', ['balance' => 50000], 8000));
    }

    public function test_savings_breakdown_low_balance_and_switch_to_cash(): void
    {
        $m = Member::factory()->create(['balance' => 15000]);
        $c = $this->cart()->set('paymentMethod', 'tabungan')->call('selectMember', $m->id)
            ->assertSee('Saldo sekarang')->assertSee('Dipotong untuk belanja')->assertSee('Rp7.000')
            ->assertSee('Saldo hampir habis')->assertSee('menyetujui saldo tabungannya dipotong Rp8.000');

        $c->set('savingsConsent', true)->call('clearMember')->assertSet('savingsConsent', false);

        $poor = Member::factory()->create(['balance' => 3000]);
        $c->call('selectMember', $poor->id)->assertSee('Kekurangan')->assertSee('Rp5.000')->assertDontSee('menyetujui saldo')
            ->call('switchToCash')->assertSet('paymentMethod', 'tunai')->set('paid', 10000)->call('pay')->assertHasNoErrors();
        $this->assertSame('tunai', Transaction::sole()->payment_method);
    }

    public function test_receipt_shows_remaining_balance_for_savings(): void
    {
        $tx = ['invoice_number' => 'INV-1', 'created_at' => now(), 'cashier' => 'Kasir', 'items' => [], 'subtotal' => 8000, 'tax' => 0, 'total' => 8000,
            'payment_method' => 'tabungan', 'payment_method_label' => 'Saldo tabungan', 'paid' => 8000, 'change' => 0, 'balance_after' => 42000];
        $profile = app(\App\Services\ReceiptPresenter::class)->profile();
        $html = view('livewire.kasir.partials.receipt', ['tx' => $tx, 'profile' => $profile, 'rp' => fn ($n) => 'Rp'.number_format($n, 0, ',', '.')])->render();
        $this->assertStringContainsString('Sisa saldo', $html);
        $this->assertStringContainsString('Rp42.000', $html);
        $this->assertStringNotContainsString('Kembali', $html);
    }

    public function test_bon_respects_limit_and_overdue(): void
    {
        $c = $this->cart()->set('paymentMethod', 'bon');
        $fresh = Member::factory()->create(['type' => 'siswa']);
        $this->assertSame(['limit' => 50_000, 'outstanding' => 0], array_intersect_key(app(MemberCreditService::class)->summary($fresh), ['limit' => 1, 'outstanding' => 1]));
        $c->call('selectMember', $fresh->id)->assertSee('Jatuh tempo bon')->set('bonDays', 30)->call('pay')->assertHasErrors('bonConsent');

        $credit = ['available' => 5_000, 'overdue' => false];
        $this->assertSame(3_000, Cart::shortfall('bon', ['credit' => $credit, 'balance' => 0], 8_000));

        $full = Member::factory()->create(['type' => 'siswa']);
        MemberCredit::create(['number' => 'BON-A', 'member_id' => $full->id, 'amount' => 45000, 'due_at' => today()->addWeek()]);
        $c->call('selectMember', $full->id)->assertSee('Melebihi sisa batas bon sebesar Rp3.000');

        $late = Member::factory()->create(['type' => 'guru']);
        MemberCredit::create(['number' => 'BON-B', 'member_id' => $late->id, 'amount' => 5000, 'due_at' => today()->subDays(2)]);
        $c->call('selectMember', $late->id)->call('pay')->assertHasErrors('memberId')->assertSee('lewat jatuh tempo');
        $this->assertSame(0, Transaction::count());
    }

    public function test_bon_form_due_date_note_and_breakdown(): void
    {
        $m = Member::factory()->create(['type' => 'siswa']);
        MemberCredit::create(['number' => 'BON-LAMA', 'member_id' => $m->id, 'amount' => 12000, 'due_at' => today()->addDays(5)]);

        $c = $this->cart()->set('paymentMethod', 'bon')->call('selectMember', $m->id)
            ->assertSee('Bon berjalan')->assertSee('Rp20.000')
            ->assertSee('Bon tertua jatuh tempo')->assertSee('Tanggal lain')->assertSee('menyetujui bon Rp8.000');
        $this->assertSame(now()->addDays(14)->toDateString(), $c->instance()->bonDueDate());

        $c->set('bonDays', 0)->assertSee('Tanggal jatuh tempo')->set('bonConsent', true)->call('pay')->assertHasErrors(['bonDueAt' => 'required']);
        $c->set('bonDueAt', now()->addDays(61)->toDateString())->call('pay')->assertHasErrors('bonDueAt');
        $c->set('bonDueAt', now()->toDateString())->call('pay')->assertHasErrors('bonDueAt');
        $c->set('bonDueAt', now()->addDays(20)->toDateString())->set('bonNote', str_repeat('x', 101))->call('pay')->assertHasErrors('bonNote');
        $c->set('bonNote', 'Dilunasi orang tua')->call('pay')->assertHasNoErrors()->assertSee('No. bon')->assertSee('Jatuh tempo');

        $tx = Transaction::sole();
        $credit = MemberCredit::where('transaction_id', $tx->id)->sole();
        $this->assertSame(['bon', $m->id, 8000, now()->addDays(20)->toDateString(), 'Dilunasi orang tua', 'belum'],
            [$tx->payment_method, $tx->member_id, $credit->amount, $credit->due_at->toDateString(), $credit->note, $credit->status]);

        $c->call('newTransaction')->call('add', $this->pid('ATK001'))->call('openPayment')
            ->assertSet('bonDays', 14)->assertSet('bonNote', '')->assertSet('bonConsent', false)->assertSet('memberId', null);
    }

    public function test_bon_form_hidden_when_over_limit(): void
    {
        $m = Member::factory()->create(['type' => 'siswa']);
        $c = Livewire::test(Cart::class);
        foreach (range(1, 15) as $_) {
            $c->call('add', $this->pid('ATK001')); // 15 × Rp4.000 = Rp60.000 > batas siswa Rp50.000
        }
        $c->call('openPayment')->set('paymentMethod', 'bon')->call('selectMember', $m->id)
            ->assertSee('Melebihi sisa batas bon sebesar Rp10.000')->assertDontSee('Tanggal lain');
    }

    public function test_cash_and_qris_still_complete(): void
    {
        $this->cart()->set('paymentMethod', 'qris')->call('pay')->assertHasNoErrors();
        $this->assertSame('qris', Transaction::sole()->payment_method);
    }
}
