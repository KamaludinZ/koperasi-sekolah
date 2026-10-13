<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Receiving;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\SupplierBill;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    private function po(string $status): PurchaseOrder
    {
        return PurchaseOrder::with('items')->where('status', $status)->firstOrFail();
    }

    public function test_page_and_links(): void
    {
        $partial = $this->po('sebagian');
        $this->get('/pengadaan/penerimaan')->assertOk()->assertSee('Pesanan yang datang')->assertSee($partial->number)->assertDontSee('Data contoh');
        $this->get("/pengadaan/penerimaan?pesanan={$partial->id}")->assertOk()->assertSee('Barang dari '.$partial->number);
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/penerimaan')->assertForbidden();
    }

    public function test_choosing_order_prefills_remaining_quantities(): void
    {
        $c = Livewire::test(Receiving::class);
        $this->assertSame([$this->po('sebagian')->id, $this->po('dipesan')->id], array_column($c->instance()->waiting, 'id'));
        $c->call('save')->assertHasErrors('orderId');
        $c->set('orderId', (string) $this->po('sebagian')->id);
        $this->assertSame(260 - 182, array_sum($c->get('qty')));
        $c->assertSee('Lengkap');
    }

    public function test_partial_then_full_receipt_adds_stock_and_bills(): void
    {
        $order = $this->po('dipesan');
        $first = $order->items[0];
        $before = Product::find($first->product_id)->stock;
        $c = Livewire::test(Receiving::class)->set('orderId', (string) $order->id);

        $c->set('qty', array_fill_keys($order->items->pluck('id')->all(), 0))->call('save')->assertHasErrors('qty');
        $c->set("qty.{$first->id}", 999)->call('save')->assertHasErrors("qty.{$first->id}");
        $c->set("qty.{$first->id}", 10)->set('deliveryNote', 'SJ-1001')->set('receivedAt', '2026-10-20')->call('save')->assertHasErrors('receivedAt');
        $c->set('receivedAt', '2026-10-14')->call('save')->assertHasNoErrors()->assertRedirect(route('pengadaan.penerimaan'));

        $order->refresh();
        $this->assertSame('sebagian', $order->status);
        $this->assertSame($before + 10, Product::find($first->product_id)->stock);
        $receipt = GoodsReceipt::latest('id')->first();
        $this->assertSame(['SJ-1001', 10, 10 * $first->cost], [$receipt->delivery_note, $receipt->total_quantity, $receipt->total_value]);
        $this->assertSame('Penerimaan '.$receipt->number, StockMovement::find($receipt->items[0]->stock_movement_id)->note);
        $bill = SupplierBill::where('goods_receipt_id', $receipt->id)->sole();
        $this->assertSame([10 * $first->cost, '2026-10-21'], [$bill->amount, $bill->due_at->toDateString()]); // tempo 7 hari
        $this->assertStringContainsString('sisanya masih ditunggu', session('toast')['message']);

        Livewire::test(Receiving::class)->set('orderId', (string) $order->id)->call('save')->assertHasNoErrors();
        $this->assertSame('diterima', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->received_at);
        $this->assertNotContains($order->id, array_column(Livewire::test(Receiving::class)->instance()->waiting, 'id'));
        $this->assertSame(2, ActivityLog::where('event', 'purchase.received')->count());
    }

    public function test_api_receive_and_cost_price_update(): void
    {
        $order = $this->po('dipesan');
        $item = $order->items[0];
        $item->update(['cost' => (int) Product::find($item->product_id)->cost_price + 100]);

        $this->postJson("/pengadaan/pesanan/{$order->id}/terima", ['received_at' => '2026-10-14', 'items' => [$item->id => 5]])
            ->assertCreated()->assertJsonPath('data.total_quantity', 5)->assertJsonPath('data.order_status', 'sebagian');
        $this->assertSame($item->cost, (int) Product::find($item->product_id)->cost_price);
        $this->assertStringStartsWith('Penerimaan TB-', ActivityLog::where('event', 'product.updated')->latest('id')->value('reason'));

        $this->postJson("/pengadaan/pesanan/{$order->id}/terima", ['received_at' => '2026-10-14', 'items' => [99999 => 1]])->assertJsonValidationErrors('items.99999');
        $this->postJson("/pengadaan/pesanan/{$this->po('draf')->id}/terima", ['received_at' => '2026-10-14', 'items' => [1 => 1]])->assertJsonValidationErrors('status');
        $this->getJson('/pengadaan/penerimaan/data')->assertOk()->assertJsonPath('data.0.qty', 5);

        $this->loginAs('kasir@koperasi.test');
        $this->postJson("/pengadaan/pesanan/{$order->id}/terima", ['received_at' => '2026-10-14', 'items' => [$item->id => 1]])->assertForbidden();
    }
}
