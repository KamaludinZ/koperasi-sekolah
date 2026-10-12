<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\ReceiptPresenter;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Pajak & pembulatan dari Pengaturan diterapkan di kasir dan disimpan sebagai snapshot transaksi. */
class TaxRoundingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function tax(array $values): void
    {
        app(SettingsService::class)->save('tax', $values);
    }

    /** 2 × Buku Tulis 4.000 + 1 × Air Mineral 3.500 = 11.500 */
    private function items(): array
    {
        return [$this->pid('ATK001') => 2, $this->pid('MNM001') => 1];
    }

    public function test_without_tax_and_rounding_total_equals_subtotal(): void
    {
        $tx = app(CheckoutService::class)->checkout($this->items(), 'tunai', 20000);

        $this->assertSame('11500.00', $tx->total);
        $this->assertSame('0.00', $tx->tax);
        $this->assertSame('0.00', $tx->rounding);
        $this->assertNull($tx->tax_label);
    }

    public function test_added_tax_and_cash_rounding(): void
    {
        $this->tax(['enabled' => true, 'label' => 'PPN', 'rate' => 11, 'rounding' => 'up', 'rounding_unit' => 500, 'cash_only' => true]);

        $cash = app(CheckoutService::class)->checkout($this->items(), 'tunai', 20000);
        $this->assertSame('1265.00', $cash->tax);           // 11% × 11.500
        $this->assertSame('235.00', $cash->rounding);       // 12.765 → 13.000
        $this->assertSame('13000.00', $cash->total);
        $this->assertSame('7000.00', $cash->change);
        $this->assertSame('PPN', $cash->tax_label);
        $this->assertSame('11.00', $cash->tax_rate);

        $qris = app(CheckoutService::class)->checkout($this->items(), 'qris', null);
        $this->assertSame('0.00', $qris->rounding);         // pembulatan khusus tunai
        $this->assertSame('12765.00', $qris->total);
        $this->assertSame('12765.00', $qris->paid);
    }

    public function test_included_tax_and_rounding_down_for_all_methods(): void
    {
        $this->tax(['enabled' => true, 'rate' => 10, 'included' => true, 'rounding' => 'down', 'rounding_unit' => 1000, 'cash_only' => false]);

        $tx = app(CheckoutService::class)->checkout($this->items(), 'qris', null);

        $this->assertSame('0.00', $tx->tax);                // tidak menambah total
        $this->assertSame('1045.00', $tx->tax_included);    // 11.500 − 11.500/1,1
        $this->assertSame('-500.00', $tx->rounding);
        $this->assertSame('11000.00', $tx->total);
        $this->assertEquals((float) $tx->total, $tx->subtotal - $tx->discount + $tx->tax + $tx->rounding);
    }

    public function test_cash_must_cover_rounded_total(): void
    {
        $this->tax(['rounding' => 'up', 'rounding_unit' => 1000]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CheckoutService::class)->checkout($this->items(), 'tunai', 11500); // dibulatkan jadi 12.000
    }

    public function test_snapshot_survives_later_settings_change_and_shows_on_receipt(): void
    {
        $this->tax(['enabled' => true, 'label' => 'PPN', 'rate' => 11, 'rounding' => 'nearest', 'rounding_unit' => 100]);
        $tx = app(CheckoutService::class)->checkout($this->items(), 'tunai', 20000);   // 12.765 → 12.800
        $this->tax(['enabled' => false, 'rounding' => 'none']);

        $data = app(ReceiptPresenter::class)->present($tx->fresh());
        $this->assertSame(1265, $data['tax']);
        $this->assertSame(35, $data['rounding']);
        $this->assertSame(12800, $data['total']);

        $this->get(route('kasir.transactions.receipt', ['transaction' => $tx->id, 'cetak' => 0]))
            ->assertOk()->assertSee('PPN 11%')->assertSee('Pembulatan')->assertSee('12.800');
        $this->get(route('transaksi.show', $tx->id))->assertOk()->assertSee('PPN 11%')->assertSee('Pembulatan');

        $log = ActivityLog::where('event', 'sale.created')->first();
        $this->assertSame(35, $log->properties['rounding']);
        $this->assertSame(12800, $log->amount);
    }

    public function test_cart_shows_tax_rounding_and_per_method_totals(): void
    {
        $this->tax(['enabled' => true, 'label' => 'PPN', 'rate' => 2.5, 'rounding' => 'up', 'rounding_unit' => 500]);

        $cart = Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK001'), 2)
            ->call('add', $this->pid('MNM001'));

        $summary = $cart->instance()->summary;
        $this->assertSame(288, $summary['tax']);                 // 2,5% × 11.500 = 287,5 → 288
        $this->assertSame(12000, $summary['total']);             // 11.788 → 12.000 (tunai)
        $this->assertSame(212, $summary['rounding']);
        $this->assertSame(['payable' => 11788, 'rounding' => 0], $summary['totals']['qris']);
        $cart->assertSee('PPN (2,5%)')->assertSee('Pembulatan');

        $cart->call('openPayment')->set('paid', 11788)->call('pay')->assertHasErrors('paid');
        $cart->set('paid', 12000)->call('pay')->assertHasNoErrors()->assertDispatched('transaction-completed');
        $this->assertSame('12000.00', Transaction::latest('id')->first()->total);
    }

    public function test_cart_qris_payment_uses_unrounded_total(): void
    {
        $this->tax(['rounding' => 'up', 'rounding_unit' => 1000, 'cash_only' => true]);

        Livewire::test(Cart::class)
            ->call('setQty', $this->pid('ATK001'), 2)
            ->call('add', $this->pid('MNM001'))
            ->call('openPayment')
            ->set('paymentMethod', 'qris')
            ->call('pay')
            ->assertHasNoErrors();

        $tx = Transaction::latest('id')->first();
        $this->assertSame('11500.00', $tx->total);
        $this->assertSame('11500.00', $tx->paid);
    }
}
