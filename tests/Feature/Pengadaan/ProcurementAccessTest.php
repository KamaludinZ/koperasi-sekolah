<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\OrderForm;
use App\Livewire\Pengadaan\Payables;
use App\Livewire\Pengadaan\Receiving;
use App\Livewire\Pengadaan\SupplierForm;
use App\Livewire\Pengadaan\Suppliers;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierBill;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ProcurementAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    /** Admin membagikan menu Pengadaan ke kasir: boleh melihat, tidak boleh mengubah. */
    private function shareMenuWithCashier(): void
    {
        $role = Role::named('kasir');
        $role->update(['permissions' => [...$role->permissions, 'pengadaan']]);
        $this->loginAs('kasir');
    }

    public function test_viewer_sees_pages_but_not_write_controls(): void
    {
        $this->shareMenuWithCashier();
        $this->get('/pengadaan')->assertOk()->assertDontSee('Buat pesanan');
        $this->get('/pengadaan/supplier')->assertOk()->assertDontSee('Tambah supplier');
        $this->get('/pengadaan/pesanan')->assertOk()->assertDontSee('Buat pesanan');
        $this->get('/pengadaan/hutang')->assertOk()->assertDontSee('Bayar hutang');
        $this->get('/pengadaan/hutang')->assertDontSee(route('pengadaan.penerimaan'));

        foreach (['/pengadaan/supplier/baru', '/pengadaan/pesanan/baru', '/pengadaan/penerimaan', '/pengadaan/hutang/bayar'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->postJson('/pengadaan/supplier', ['name' => 'X'])->assertForbidden();
        $this->getJson('/pengadaan/hutang/data')->assertOk();
    }

    public function test_viewer_cannot_write_through_livewire(): void
    {
        $this->shareMenuWithCashier();
        $bill = SupplierBill::where('status', 'belum')->first();
        Livewire::test(Payables::class)->call('pay', $bill->id)->assertSet('payingId', null);
        Livewire::test(Payables::class)->set('payingId', $bill->id)->set('amount', 1000)->set('paidAt', '2026-10-14')->call('savePayment')->assertForbidden();
        Livewire::test(SupplierForm::class)->set('name', 'Toko Baru')->set('phone', '0812 1111 2222')->call('save')->assertForbidden();
        Livewire::test(OrderForm::class)->call('save', 'dipesan')->assertForbidden();
        Livewire::test(Receiving::class)->call('save')->assertForbidden();
        $jaya = Supplier::where('name', 'Toko Grosir Jaya')->value('id');
        Livewire::test(Suppliers::class)->call('show', $jaya)->assertDontSee('Hapus supplier…')->call('delete');
        $this->assertNotSoftDeleted(Supplier::find($jaya));
        $this->assertSame(0, $bill->fresh()->paid);
    }

    public function test_search_indexes_exist(): void
    {
        $names = fn (string $table) => collect(Schema::getIndexes($table))->pluck('columns')->map(fn ($c) => implode(',', $c))->all();
        $this->assertContains('phone', $names('suppliers'));
        $this->assertContains('ordered_at,id', $names('purchase_orders'));
        $this->assertContains('product_code', $names('purchase_order_items'));
        $this->assertContains('received_at,id', $names('goods_receipts'));
        $this->assertContains('file_name', $names('product_imports'));
    }
}
