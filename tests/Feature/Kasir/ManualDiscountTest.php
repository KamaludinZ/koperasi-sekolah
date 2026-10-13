<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManualDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    /** Keranjang 5 buku tulis (Rp4.000) = Rp20.000. */
    private function cart()
    {
        return Livewire::test(Cart::class)->call('setQty', $this->pid('ATK001'), 5);
    }

    public function test_percent_discount_reduces_total(): void
    {
        $this->loginAs('kasir');
        $c = $this->cart()
            ->assertSee('Tambah diskon')
            ->call('editDiscount')->assertSet('editingDiscount', true)
            ->set('discountType', 'persen')->set('discountValue', 10)->set('discountReason', 'Pelanggan tetap')
            ->call('applyDiscount')->assertHasNoErrors()
            ->assertSet('editingDiscount', false)
            ->assertSee('Diskon 10%')->assertSee('Pelanggan tetap');

        $this->assertSame(2000, $c->instance()->summary['discount']);
        $this->assertSame(18000, $c->instance()->summary['total']);
    }

    public function test_nominal_discount_and_validation(): void
    {
        $this->loginAs('kasir');
        $this->cart()
            ->call('editDiscount')
            ->set('discountType', 'nominal')
            ->call('applyDiscount')->assertHasErrors(['discountValue' => 'required'])
            ->set('discountValue', 1000)
            ->call('applyDiscount')->assertHasErrors(['discountReason' => 'Tulis alasan diskon.'])
            ->set('discountValue', 25000)->set('discountReason', 'Kegiatan sekolah')
            ->call('applyDiscount')->assertHasErrors('discountValue') // di atas batas Rp20.000
            ->set('discountValue', 1500)
            ->call('applyDiscount')->assertHasNoErrors()
            ->assertSet('discount', ['type' => 'nominal', 'value' => 1500, 'reason' => 'Kegiatan sekolah']);
    }

    public function test_percent_above_cashier_limit_needs_admin(): void
    {
        $this->loginAs('kasir');
        $this->cart()
            ->call('editDiscount')
            ->set('discountValue', 15)->set('discountReason', 'Penyesuaian harga')
            ->call('applyDiscount')
            ->assertHasErrors('discountValue')
            ->assertSee('Minta persetujuan admin')
            ->assertSet('discount', null);
    }

    public function test_discount_is_saved_with_transaction_and_tax_uses_net(): void
    {
        app(SettingsService::class)->save('tax', ['enabled' => true, 'label' => 'PPN', 'rate' => 10, 'included' => false, 'rounding' => 'none', 'rounding_unit' => 100, 'cash_only' => true]);

        $c = $this->cart()
            ->call('editDiscount')->set('discountValue', 10)->set('discountReason', 'Pelanggan tetap')->call('applyDiscount');
        $this->assertSame(19800, $c->instance()->summary['total']); // (20.000 − 2.000) + PPN 10%

        $c->call('openPayment')->set('paid', 20000)->call('pay')->assertHasNoErrors()
            ->assertSet('discount', null);

        $tx = Transaction::latest('id')->first();
        $this->assertSame([20000, 2000, 1800, 19800], [(int) $tx->subtotal, (int) $tx->discount, (int) $tx->tax, (int) $tx->total]);
        $this->assertSame(200, (int) $tx->change);
    }

    public function test_remove_and_clear_reset_discount(): void
    {
        $c = $this->cart()->call('editDiscount')->set('discountType', 'nominal')->set('discountValue', 1000)->set('discountReason', 'x y z')->call('applyDiscount');
        $c->call('removeDiscount')->assertSet('discount', null);
        $this->assertSame(20000, $c->instance()->summary['total']);

        $c->call('editDiscount')->set('discountType', 'nominal')->set('discountValue', 1000)->set('discountReason', 'x y z')->call('applyDiscount')
            ->call('clear')->assertSet('discount', null);
    }

    public function test_service_enforces_limits_and_records_discount_row(): void
    {
        $this->loginAs('kasir');
        $checkout = fn (array $discount) => app(CheckoutService::class)->checkout([$this->pid('ATK001') => 5], 'qris', null, auth()->id(), null, $discount);

        foreach ([
            [['type' => 'persen', 'value' => 15, 'reason' => 'x'], 'Melebihi batas diskon kasir'],
            [['type' => 'nominal', 'value' => 25000, 'reason' => 'x'], 'melebihi subtotal'],
            [['type' => 'nominal', 'value' => 1000], 'Tulis alasan diskon'],
            [['type' => 'bonus', 'value' => 1000, 'reason' => 'x'], 'Jenis diskon tidak dikenal'],
        ] as [$spec, $message]) {
            try {
                $checkout($spec);
                $this->fail('Diskon tidak ditolak: '.json_encode($spec));
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, $e->errors()['discount'][0]);
            }
        }
        $this->assertSame(0, Transaction::count());

        $tx = $checkout(['type' => 'persen', 'value' => 10, 'reason' => ' Pelanggan tetap ']);
        $this->assertSame([20000, 2000, 18000], [(int) $tx->subtotal, (int) $tx->discount, (int) $tx->total]);
        $row = $tx->discounts()->sole();
        $this->assertSame(['manual', 'Diskon manual 10%', 2000, 'Pelanggan tetap'], [$row->source, $row->label, (int) $row->amount, $row->reason]);
    }

    public function test_admin_may_exceed_limit_and_settings_change_limits(): void
    {
        $tx = app(CheckoutService::class)->checkout([$this->pid('ATK001') => 5], 'qris', null, auth()->id(), null, ['type' => 'persen', 'value' => 50]);
        $this->assertSame(10000, (int) $tx->discount);

        app(SettingsService::class)->save('discount', ['max_percent' => 25, 'reason_required' => false]);
        $this->loginAs('kasir');
        $tx = app(CheckoutService::class)->checkout([$this->pid('ATK001') => 5], 'qris', null, auth()->id(), null, ['type' => 'persen', 'value' => 20]);
        $this->assertSame(4000, (int) $tx->discount);

        app(SettingsService::class)->save('discount', ['enabled' => false]);
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->checkout([$this->pid('ATK001') => 5], 'qris', null, auth()->id(), null, ['type' => 'nominal', 'value' => 1000]);
    }

    public function test_checkout_api_accepts_discount(): void
    {
        $this->loginAs('kasir');
        $this->postJson('/kasir/transaksi', [
            'items' => [['product_id' => $this->pid('ATK001'), 'quantity' => 5]],
            'payment_method' => 'qris',
            'discount' => ['type' => 'nominal', 'value' => 1500, 'reason' => 'Kemasan rusak'],
        ])->assertCreated()->assertJsonPath('data.discount', '1500.00');

        $this->postJson('/kasir/transaksi', [
            'items' => [['product_id' => $this->pid('ATK001'), 'quantity' => 5]],
            'payment_method' => 'qris',
            'discount' => ['type' => 'persen', 'value' => 30, 'reason' => 'x'],
        ])->assertUnprocessable()->assertJsonValidationErrors('discount');
    }

    public function test_cart_shows_admin_can_exceed(): void
    {
        $this->cart()->call('editDiscount')->assertSee('Anda admin')
            ->set('discountValue', 30)->call('applyDiscount')->assertHasNoErrors()
            ->call('openPayment')->set('paymentMethod', 'qris')->call('pay')->assertHasNoErrors();
        $this->assertSame(6000, (int) Transaction::latest('id')->first()->discount);
    }
}
