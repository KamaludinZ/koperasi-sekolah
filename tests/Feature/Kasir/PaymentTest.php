<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use Livewire\Livewire;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_payment_panel_requires_items(): void
    {
        Livewire::test(Cart::class)
            ->call('openPayment')
            ->assertSet('paying', false);
    }

    public function test_cash_payment_calculates_change_and_clears_cart(): void
    {
        $cart = Livewire::test(Cart::class)
            ->call('setQty', 1, 2) // 2 x 4.000
            ->call('add', 9)       // 1 x 3.500
            ->call('openPayment')
            ->assertSet('paying', true)
            ->assertSee('Uang pas')
            ->set('paid', 20000)
            ->call('pay')
            ->assertHasNoErrors()
            ->assertDispatched('transaction-completed')
            ->assertSet('items', [])
            ->assertSet('paying', false)
            ->assertSee('Transaksi berhasil');

        $tx = $cart->get('lastTransaction');
        $this->assertSame(11500, $tx['total']);
        $this->assertSame(8500, $tx['change']);
        $this->assertCount(2, $tx['items']);
    }

    public function test_underpayment_is_rejected(): void
    {
        Livewire::test(Cart::class)
            ->call('add', 12)
            ->call('openPayment')
            ->set('paid', 10000)
            ->call('pay')
            ->assertHasErrors(['paid' => 'min'])
            ->assertSet('items', [12 => 1])
            ->assertSet('lastTransaction', null);
    }

    public function test_non_cash_payment_is_exact(): void
    {
        $cart = Livewire::test(Cart::class)
            ->call('add', 12)
            ->call('openPayment')
            ->set('paymentMethod', 'qris')
            ->call('pay')
            ->assertHasNoErrors();

        $this->assertSame(0, $cart->get('lastTransaction')['change']);
    }

    public function test_new_transaction_resets_success_state(): void
    {
        Livewire::test(Cart::class)
            ->call('add', 1)
            ->call('openPayment')
            ->set('paid', 4000)
            ->call('pay')
            ->call('newTransaction')
            ->assertSet('lastTransaction', null)
            ->assertSet('paying', false);
    }

    public function test_success_dialog_shows_printable_receipt(): void
    {
        Livewire::test(Cart::class)
            ->call('setQty', 2, 3)
            ->call('openPayment')
            ->set('paid', 10000)
            ->call('pay')
            ->assertSeeHtml('print-area')
            ->assertSee('Cetak Struk')
            ->assertSee('Pulpen Hitam')
            ->assertSee('3 x 3.000')
            ->assertSee('Kembali');
    }
}
