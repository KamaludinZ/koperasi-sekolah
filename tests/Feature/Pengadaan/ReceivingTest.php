<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Receiving;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_page_and_links(): void
    {
        $this->get('/pengadaan/penerimaan')->assertOk()->assertSee('Pesanan yang datang')->assertSee('PO-202610-0006')->assertSee('SJ-0912');
        $this->get('/pengadaan/penerimaan?pesanan=6')->assertOk()->assertSee('Barang dari PO-202610-0006');
        $this->get('/pengadaan')->assertSee(route('pengadaan.penerimaan'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/penerimaan')->assertForbidden();
    }

    public function test_choosing_order_prefills_remaining_quantities(): void
    {
        $c = Livewire::test(Receiving::class);
        $this->assertSame([6, 7], array_column($c->instance()->waiting, 'id'));   // terlambat dulu
        $c->call('save')->assertHasErrors('orderId');

        $c->set('orderId', '6');
        $this->assertSame(80, array_sum($c->get('qty')));
        $c->assertSee('Lengkap');
    }

    public function test_partial_then_full_receipt_updates_order(): void
    {
        $c = Livewire::test(Receiving::class)->set('orderId', '7');
        $lines = $c->instance()->lines;
        $first = $lines[0]['product_id'];

        $c->set('qty', array_fill_keys(array_column($lines, 'product_id'), 0))->call('save')->assertHasErrors('qty');
        $c->set("qty.{$first}", 999)->call('save')->assertHasErrors("qty.{$first}");
        $c->set("qty.{$first}", 10)->set('deliveryNote', 'SJ-1001')->set('receivedAt', '2026-10-20')->call('save')->assertHasErrors('receivedAt');
        $c->set('receivedAt', '2026-10-14')->call('save')->assertHasNoErrors()->assertRedirect(route('pengadaan.penerimaan'));

        $order = collect(MockData::purchaseOrders())->firstWhere('id', 7);
        $this->assertSame(['sebagian', 10], [$order['status'], $order['received_qty']]);
        $this->assertSame(['SJ-1001', 10, 'Admin Koperasi'], [MockData::receipts()[0]['delivery_note'], MockData::receipts()[0]['qty'], MockData::receipts()[0]['user']]);
        $this->assertStringContainsString('sisanya masih ditunggu', session('toast')['message']);

        Livewire::test(Receiving::class)->set('orderId', '7')->call('save')->assertHasNoErrors();
        $this->assertSame('diterima', collect(MockData::purchaseOrders())->firstWhere('id', 7)['status']);
        $this->assertNotContains(7, array_column(Livewire::test(Receiving::class)->instance()->waiting, 'id'));
    }
}
