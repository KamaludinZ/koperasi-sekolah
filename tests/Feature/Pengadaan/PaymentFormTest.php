<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\PaymentForm;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Services\GoodsReceiptService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentFormTest extends TestCase
{
    use RefreshDatabase;

    private string $berkah;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, UserSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
        // Terima sebagian pesanan Berkah yang masih dipesan → tagihan kedua untuk supplier yang sama.
        $order = PurchaseOrder::with('items')->where('status', 'dipesan')->first();
        app(GoodsReceiptService::class)->receive($order, [$order->items[0]->id => 20], ['received_at' => '2026-10-14']);
        $this->berkah = (string) Supplier::where('name', 'UD Berkah Pangan')->value('id');
    }

    public function test_page_and_link(): void
    {
        $this->get('/pengadaan/hutang/bayar?supplier='.$this->berkah)->assertOk()->assertSee('Bayar hutang supplier')->assertSee('PO-202610-0003');
        $this->get('/pengadaan/hutang')->assertSee(route('pengadaan.hutang.bayar'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/hutang/bayar')->assertForbidden();
    }

    public function test_amount_is_allocated_to_nearest_due_first(): void
    {
        $c = Livewire::test(PaymentForm::class)->set('supplierId', $this->berkah);
        $bills = $c->instance()->bills;
        [$first, $second] = [$bills[0], $bills[1]];
        $this->assertTrue($first['due_at'] <= $second['due_at']);
        $this->assertSame($first['remaining'] + $second['remaining'], $c->get('total'));

        $c->set('total', $first['remaining'] + 1000);
        $this->assertSame([$first['id'] => $first['remaining'], $second['id'] => 1000], $c->get('alloc'));
        $c->set('selected', [$second['id']]);
        $this->assertSame($second['remaining'], $c->get('total'));
        $c->set("alloc.{$first['id']}", 50_000);
        $this->assertSame($second['remaining'] + 50_000, $c->get('total'));
    }

    public function test_validation_and_saving_multiple_payments(): void
    {
        Livewire::test(PaymentForm::class)->call('save')->assertHasErrors('supplierId');

        $c = Livewire::test(PaymentForm::class)->set('supplierId', $this->berkah);
        [$first, $second] = $c->instance()->bills;
        $c->set("alloc.{$first['id']}", $first['remaining'] + 1)->call('save')->assertHasErrors("alloc.{$first['id']}");
        $c->set('total', 0)->call('save')->assertHasErrors('total');
        $c->set('total', $first['remaining'] + 10_000)->set('reference', 'TRF-777')->call('save')->assertHasNoErrors()->assertRedirect(route('pengadaan.hutang'));

        $this->assertSame('lunas', SupplierBill::find($first['id'])->status);
        $this->assertSame([10_000, 'sebagian'], [SupplierBill::find($second['id'])->paid, SupplierBill::find($second['id'])->status]);
        $this->assertStringContainsString('1 tagihan lunas', session('toast')['message']);
        $this->assertSame(4, GoodsReceipt::count());
    }
}
