<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
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
        $this->cart()
            ->call('editDiscount')
            ->set('discountType', 'nominal')
            ->call('applyDiscount')->assertHasErrors(['discountValue' => 'required', 'discountReason' => 'required'])
            ->set('discountValue', 25000)->set('discountReason', 'Kegiatan sekolah')
            ->call('applyDiscount')->assertHasErrors('discountValue') // di atas batas Rp20.000
            ->set('discountValue', 1500)
            ->call('applyDiscount')->assertHasNoErrors()
            ->assertSet('discount', ['type' => 'nominal', 'value' => 1500, 'reason' => 'Kegiatan sekolah']);
    }

    public function test_percent_above_cashier_limit_needs_admin(): void
    {
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

    public function test_service_clamps_discount_to_subtotal(): void
    {
        $tx = app(CheckoutService::class)->checkout([$this->pid('ATK002') => 1], 'qris', null, null, null, 99999, 'uji');

        $this->assertSame([3000, 3000, 0], [(int) $tx->subtotal, (int) $tx->discount, (int) $tx->total]);
    }
}
