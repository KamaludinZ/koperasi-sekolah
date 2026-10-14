<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\Product;
use App\Models\SavingType;
use App\Models\Transaction;
use App\Services\MemberSavingService;
use App\Services\TransactionReturnService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Kasir memotong saldo simpanan (jenis yang boleh ditarik) saat transaksi anggota. */
class SimpananPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    private SavingType $sukarela;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->loginAs('kasir@koperasi.test');
        $this->andi = Member::factory()->create(['name' => 'Andi']);
        $this->sukarela = SavingType::where('code', 'SUKARELA')->first();
        app(MemberSavingService::class)->deposit($this->andi, $this->sukarela, 10000);
        app(MemberSavingService::class)->deposit($this->andi, SavingType::where('code', 'POKOK')->first(), 50000); // tidak boleh ditarik
    }

    private function balance(): int
    {
        return (int) $this->andi->savingAccounts()->where('saving_type_id', $this->sukarela->id)->value('balance');
    }

    public function test_pay_with_simpanan_from_kasir(): void
    {
        $c = Livewire::test(Cart::class)->call('add', $this->pid('ATK001'))->call('add', $this->pid('ATK001'))->call('openPayment') // Rp8.000
            ->set('paymentMethod', 'simpanan')->call('selectMember', $this->andi->id)
            ->assertSee('Simpanan Sukarela · saldo Rp10.000')->assertDontSee('Simpanan Pokok · saldo')
            ->call('pay')->assertHasErrors('savingTypeId')
            ->set('savingTypeId', $this->sukarela->id)->assertSee('Saldo setelah bayar')->call('pay')->assertHasErrors('savingsConsent')
            ->set('savingsConsent', true)->call('pay')->assertHasNoErrors();

        $tx = Transaction::sole();
        $this->assertSame(['simpanan', $this->sukarela->id, 8000], [$tx->payment_method, $tx->saving_type_id, (int) $tx->total]);
        $this->assertSame(2000, $this->balance());
        $entry = MemberSavingEntry::where('type', 'potong')->sole();
        $this->assertSame(['transaction', $tx->id], [$entry->source_type, $entry->source_id]);
    }

    public function test_insufficient_balance_rejected_without_side_effects(): void
    {
        $stock = Product::find($this->pid('ATK001'))->stock;
        $c = Livewire::test(Cart::class);
        foreach (range(1, 3) as $i) {
            $c->call('add', $this->pid('ATK001')); // Rp12.000
        }
        $c->call('openPayment')->set('paymentMethod', 'simpanan')->call('selectMember', $this->andi->id)->set('savingTypeId', $this->sukarela->id)
            ->set('savingsConsent', true)->call('pay')->assertHasErrors('memberId')->assertSee('Saldo Simpanan Sukarela kurang Rp2.000.');
        $this->assertSame(0, Transaction::count());
        $this->assertSame(10000, $this->balance());
        $this->assertSame($stock, Product::find($this->pid('ATK001'))->stock);
    }

    public function test_json_checkout_and_refund_to_simpanan(): void
    {
        $this->postJson('/kasir/transaksi', ['items' => [['product_id' => $this->pid('ATK002'), 'quantity' => 1]], 'payment_method' => 'simpanan', 'member_id' => $this->andi->id])
            ->assertJsonValidationErrors('saving_type_id');
        $tx = $this->postJson('/kasir/transaksi', ['items' => [['product_id' => $this->pid('ATK002'), 'quantity' => 2]], 'payment_method' => 'simpanan',
            'member_id' => $this->andi->id, 'saving_type_id' => $this->sukarela->id])->assertCreated()->json('data');
        $this->assertSame(4000, $this->balance());

        $t = Transaction::with('items')->find($tx['id']);
        $this->assertEqualsCanonicalizing(['simpanan', 'tunai', 'tabungan'], array_keys(TransactionReturnService::refundOptions($t)));
        $this->loginAs('admin');
        $svc = app(TransactionReturnService::class);
        $r = $svc->requestReturn($t, [$t->items->first()->id => 1], 'rusak', false, 'simpanan', null, null);
        $svc->approve($r, auth()->user());
        $this->assertSame(7000, $this->balance());
    }
}
