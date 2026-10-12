<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Services\CheckoutService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function product(string $code): Product
    {
        return Product::where('code', $code)->firstOrFail();
    }

    public function test_checkout_saves_transaction_items_and_reduces_stock(): void
    {
        $buku = $this->product('ATK001');   // 4.000, stok 120
        $air = $this->product('MNM001');    // 3.500, stok 72

        $tx = app(CheckoutService::class)->checkout([$buku->id => 2, $air->id => 1], 'tunai', 20000);

        $this->assertMatchesRegularExpression('/^INV-\d{8}-0001$/', $tx->invoice_number);
        $this->assertSame('11500.00', $tx->total);
        $this->assertSame('8500.00', $tx->change);
        $this->assertCount(2, $tx->items);
        $this->assertSame('Buku Tulis 38 Lembar', $tx->items->firstWhere('product_id', $buku->id)->product_name);

        $this->assertSame(118, $buku->fresh()->stock);
        $this->assertSame(71, $air->fresh()->stock);
        $this->assertSame(2, StockMovement::where('type', 'keluar')->where('product_id', $buku->id)->value('quantity'));
    }

    public function test_invoice_numbers_increment(): void
    {
        $id = $this->product('ATK002')->id;
        app(CheckoutService::class)->checkout([$id => 1], 'qris', null);
        $second = app(CheckoutService::class)->checkout([$id => 1], 'qris', null);

        $this->assertStringEndsWith('-0002', $second->invoice_number);
        $this->assertSame('0.00', $second->change);
    }

    public function test_insufficient_stock_rolls_back_everything(): void
    {
        $topi = $this->product('SRG002');   // stok 4
        $buku = $this->product('ATK001');

        try {
            app(CheckoutService::class)->checkout([$buku->id => 1, $topi->id => 5], 'tunai', 1_000_000);
            $this->fail('Seharusnya gagal karena stok kurang.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stok Topi Sekolah tinggal 4', $e->errors()['items'][0]);
        }

        $this->assertSame(0, Transaction::count());
        $this->assertSame(120, $buku->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_underpayment_is_rejected(): void
    {
        $roti = $this->product('MKN001');

        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->checkout([$roti->id => 1], 'tunai', 1000);
    }

    public function test_inactive_product_cannot_be_sold(): void
    {
        $roti = $this->product('MKN001');
        $roti->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->checkout([$roti->id => 1], 'tunai', 10000);
    }

    public function test_json_endpoint(): void
    {
        $roti = $this->product('MKN001');

        $this->postJson('/kasir/transaksi', [
            'items' => [['product_id' => $roti->id, 'quantity' => 3]],
            'payment_method' => 'tunai',
            'paid' => 20000,
        ])->assertCreated()
            ->assertJsonPath('data.total', '15000.00')
            ->assertJsonPath('data.change', '5000.00')
            ->assertJsonCount(1, 'data.items');

        $this->postJson('/kasir/transaksi', [
            'items' => [['product_id' => $roti->id, 'quantity' => 999]],
            'payment_method' => 'tunai',
            'paid' => 20000,
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->postJson('/kasir/transaksi', ['items' => [], 'payment_method' => 'tunai'])->assertUnprocessable();

        $this->assertSame(21, $roti->fresh()->stock);
    }

    public function test_cart_payment_persists_transaction(): void
    {
        $roti = $this->product('MKN001');

        Livewire::test(Cart::class)
            ->call('setQty', $roti->id, 2)
            ->call('openPayment')
            ->set('paid', 10000)
            ->call('pay')
            ->assertHasNoErrors()
            ->assertSee('Transaksi berhasil');

        $this->assertSame(1, Transaction::count());
        $this->assertSame(22, $roti->fresh()->stock);
    }

    public function test_cart_shows_error_when_stock_ran_out_meanwhile(): void
    {
        $topi = $this->product('SRG002');

        $cart = Livewire::test(Cart::class)
            ->call('setQty', $topi->id, 3)
            ->call('openPayment')
            ->set('paid', 100000);

        $topi->update(['stock' => 1]); // terjual di kasir lain

        $cart->call('pay')
            ->assertHasErrors('items')
            ->assertSee('Stok Topi Sekolah tinggal 1')
            ->assertSet('lastTransaction', null);
    }
}
