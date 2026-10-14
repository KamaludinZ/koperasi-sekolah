<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Checkout;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use App\Services\OrderService;
use App\Services\SavingsService;
use App\Support\PortalCart;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 09:00:00');
        $this->andi = Member::factory()->create(['name' => 'Andi', 'class' => '7A', 'password' => '246810', 'joined_at' => '2026-10-01']);
        $this->actingAsMember($this->andi);
        PortalCart::set($this->pid('ATK001'), 2); // 2 × Rp4.000
    }

    public function test_empty_cart_redirects_to_shop(): void
    {
        PortalCart::clear();
        Livewire::test(Checkout::class)->assertRedirect(route('portal.belanja'));
    }

    public function test_cash_pickup_order_reserves_stock(): void
    {
        $stock = Product::find($this->pid('ATK001'))->stock;
        Livewire::test(Checkout::class)->assertSee('Rp8.000')->assertSet('deliveryTo', 'Kelas 7A')
            ->set('payment', 'tunai')->set('pin', '123456')->call('place')->assertHasNoErrors()->assertRedirect(route('portal.pesanan.show', Order::value('id')));

        $order = Order::with('items', 'statusLogs')->firstOrFail();
        $this->assertSame(['ORD-20261014-0001', 'menunggu', 'ambil', 'tunai', 'belum', 8000, 2], [$order->code, $order->status, $order->fulfillment,
            $order->payment_method, $order->payment_status, (int) $order->total, $order->item_count]);
        $this->assertSame('Buku Tulis 38 Lembar', $order->items[0]->product_name);
        $this->assertSame($stock - 2, Product::find($this->pid('ATK001'))->stock);
        $this->assertSame('menunggu', $order->statusLogs[0]->to_status);
        $this->assertSame([], PortalCart::items());
        $this->assertSame('anggota', ActivityLog::where('event', 'order.placed')->value('actor_role'));
        $this->assertNull(\App\Models\StockMovement::where('note', 'Pesanan '.$order->code)->value('user_id')); // bukan id anggota
    }

    public function test_pay_with_tabungan_or_simpanan(): void
    {
        app(SavingsService::class)->deposit($this->andi, 5000);
        Livewire::test(Checkout::class)->assertSee('tidak cukup')->set('payment', 'tabungan')->set('pin', '123456')->call('place')->assertHasErrors('payment');
        $this->assertSame(0, Order::count());

        app(SavingsService::class)->deposit($this->andi, 5000);
        Livewire::test(Checkout::class)->set('payment', 'tabungan')->set('fulfillment', 'antar')->set('deliveryTo', 'Kelas 7A')->set('pin', '123456')->call('place')->assertHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame(['lunas', 'antar', 'Kelas 7A'], [$order->payment_status, $order->fulfillment, $order->delivery_to]);
        $this->assertEquals(2000, (float) $this->andi->fresh()->balance);
        $this->assertSame('order', $this->andi->savings()->latest('id')->value('source_type'));

        $sukarela = SavingType::where('code', 'SUKARELA')->first();
        app(MemberSavingService::class)->deposit($this->andi, $sukarela, 10000);
        PortalCart::set($this->pid('ATK002'), 1); // Rp3.000
        Livewire::test(Checkout::class)->set('payment', 'simpanan')->assertSet('savingTypeId', $sukarela->id)->set('pin', '123456')->call('place')->assertHasNoErrors();
        $entry = MemberSavingEntry::where('type', 'potong')->firstOrFail();
        $this->assertSame([3000, 7000, 'order'], [(int) $entry->amount, (int) $entry->balance_after, $entry->source_type]);
    }

    public function test_tax_and_cash_rounding_follow_settings(): void
    {
        app(\App\Services\SettingsService::class)->save('tax', ['enabled' => true, 'rate' => 11, 'included' => false, 'label' => 'PPN',
            'rounding' => 'up', 'rounding_unit' => 500, 'cash_only' => true]);
        // Rp8.000 + PPN 11% = Rp8.880 → tunai dibulatkan ke atas per Rp500 = Rp9.000
        Livewire::test(Checkout::class)->set('payment', 'tunai')->assertSee('PPN')->assertSee('Rp880')->assertSee('Rp120')->assertSeeHtml('data-total>Rp9.000')
            ->set('payment', 'qris')->assertSeeHtml('data-total>Rp8.880')
            ->set('payment', 'tunai')->set('pin', '123456')->call('place')->assertHasNoErrors();
        $o = Order::firstOrFail();
        $this->assertSame([8000, 880, 120, 9000], [(int) $o->subtotal, (int) $o->tax, (int) $o->rounding, (int) $o->total]);
    }

    public function test_deduction_preview_before_after(): void
    {
        app(SavingsService::class)->deposit($this->andi, 20000);
        Livewire::test(Checkout::class)->assertDontSee('data-deduction', false)
            ->set('payment', 'tabungan')->assertSee('data-deduction', false)->assertSee('Saldo Tabungan')->assertSee('−Rp8.000')
            ->assertSeeHtml('data-after>Rp12.000')->assertSee('tanpa menunggu persetujuan pengurus');
    }

    public function test_validation_stock_and_idempotency(): void
    {
        Livewire::test(Checkout::class)->set('fulfillment', 'antar')->set('deliveryTo', '')->set('pin', '123456')->call('place')->assertHasErrors('deliveryTo');

        Product::whereKey($this->pid('ATK001'))->update(['stock' => 1]);
        $svc = app(OrderService::class);
        try {
            $svc->place($this->andi, [$this->pid('ATK001') => 2], ['fulfillment' => 'ambil', 'payment_method' => 'tunai']);
            $this->fail('expected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tinggal 1', $e->errors()['cart'][0]);
        }
        Product::whereKey($this->pid('ATK001'))->update(['stock' => 10]);
        $a = $svc->place($this->andi, [$this->pid('ATK001') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tunai'], 'ref-1');
        $b = $svc->place($this->andi, [$this->pid('ATK001') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tunai'], 'ref-1');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(9, Product::find($this->pid('ATK001'))->stock);

        $this->expectException(ValidationException::class);
        $svc->place($this->andi, [$this->pid('ATK001') => 1], ['fulfillment' => 'kirim', 'payment_method' => 'utang']);
    }

    public function test_qris_image_visible_to_members(): void
    {
        $this->assertContains('auth:web,member', app('router')->getRoutes()->getByName('qris.image')->gatherMiddleware());
    }
}
