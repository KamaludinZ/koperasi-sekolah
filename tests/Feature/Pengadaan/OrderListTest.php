<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Orders;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_access(): void
    {
        $this->get('/pengadaan/pesanan')->assertOk()->assertSee('PO-202610-0007')->assertSee('terlambat');
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
        $this->assertSame([6], array_column($c->instance()->orders, 'id'));
        $c->call('setStatus', 'berjalan')->set('supplier', '2');
        $this->assertSame([7], array_column($c->instance()->orders, 'id'));
        $c->call('setStatus', '')->set('supplier', '')->set('search', 'konveksi');
        $this->assertSame([5], array_column($c->instance()->orders, 'id'));
        $c->call('setStatus', 'tidak-ada')->assertSet('status', '');
    }

    public function test_detail_lists_real_products_of_supplier_categories(): void
    {
        $c = Livewire::test(Orders::class)->call('show', 6);
        $lines = $c->instance()->selected['lines'];
        $this->assertCount(5, $lines);
        $this->assertSame(260, array_sum(array_column($lines, 'qty')));
        $this->assertSame(180, array_sum(array_column($lines, 'received_qty')));
        $this->assertStringStartsWith('ATK', $lines[0]['code']);
        $c->assertSee('Buku Tulis')->call('closeDetail')->assertSet('selectedId', null);
    }
}
