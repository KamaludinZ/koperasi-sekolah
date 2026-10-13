<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Orders;
use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderListTest extends TestCase
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
        return PurchaseOrder::where('status', $status)->firstOrFail();
    }

    public function test_access(): void
    {
        $this->get('/pengadaan/pesanan')->assertOk()->assertSee($this->po('sebagian')->number)->assertSee('terlambat')->assertDontSee('Data contoh');
        $this->get('/pengadaan')->assertSee(route('pengadaan.pesanan'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/pesanan')->assertForbidden();
    }

    public function test_tabs_filters_and_search(): void
    {
        $c = Livewire::test(Orders::class);
        $tabs = $c->instance()->tabs;
        $this->assertSame([6, 3, 1, 2], [$tabs[''][1], $tabs['berjalan'][1], $tabs['terlambat'][1], $tabs['diterima'][1]]);

        $c->call('setStatus', 'terlambat');
        $this->assertSame([$this->po('sebagian')->id], array_column($c->instance()->orders, 'id'));
        $berkah = Supplier::where('name', 'UD Berkah Pangan')->value('id');
        $c->call('setStatus', 'berjalan')->set('supplier', (string) $berkah);
        $this->assertSame([$this->po('dipesan')->id], array_column($c->instance()->orders, 'id'));
        $c->call('setStatus', '')->set('supplier', '')->set('search', 'konveksi');
        $this->assertSame([$this->po('draf')->id], array_column($c->instance()->orders, 'id'));
    }

    public function test_detail_send_and_cancel(): void
    {
        $partial = $this->po('sebagian');
        $c = Livewire::test(Orders::class)->call('show', $partial->id);
        $lines = $c->instance()->selected['lines'];
        $this->assertSame([5, 260, 182], [count($lines), array_sum(array_column($lines, 'qty')), array_sum(array_column($lines, 'received_qty'))]);
        $c->assertSee('Buku Tulis')->assertDontSee('Batalkan pesanan…')->call('closeDetail')->assertSet('selectedId', null);

        $draft = $this->po('draf');
        Livewire::test(Orders::class)->call('show', $draft->id)->call('send')->assertDispatched('toast', type: 'success');
        $this->assertSame('dipesan', $draft->fresh()->status);

        Livewire::test(Orders::class)->call('show', $draft->id)->set('cancelling', true)->call('cancel')->assertHasErrors('cancelReason')
            ->set('cancelReason', 'Barang sudah ada')->call('cancel')->assertDispatched('toast', type: 'success');
        $this->assertSame(['dibatalkan', 'Barang sudah ada'], [$draft->fresh()->status, $draft->fresh()->cancel_reason]);
        $this->assertSame('Barang sudah ada', ActivityLog::where('event', 'purchase.cancelled')->value('reason'));

        $this->loginAs('pengurus@koperasi.test');
        Livewire::test(Orders::class)->call('show', $this->po('dipesan')->id)->assertSee('Batalkan pesanan…');
    }
}
