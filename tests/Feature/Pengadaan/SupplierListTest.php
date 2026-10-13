<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Suppliers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierListTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_and_nav(): void
    {
        $this->get('/pengadaan/supplier')->assertOk()->assertSee('CV Sinar Ilmu')->assertDontSee('Toko Grosir Jaya');
        $this->get('/pengadaan')->assertSee(route('pengadaan.supplier'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/pengadaan/supplier')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/supplier')->assertForbidden();
    }

    public function test_filters_sort_and_summary(): void
    {
        $c = Livewire::test(Suppliers::class);
        $this->assertSame(['active' => 3, 'inactive' => 1, 'purchases_90d' => 7_370_000, 'open_payable' => 2_110_000, 'credit' => 3], $c->instance()->summary);
        $this->assertSame(['CV Sinar Ilmu', 'Konveksi Amanah', 'UD Berkah Pangan'], array_column($c->instance()->suppliers, 'name'));

        $c->call('setStatus', 'semua')->set('category', 'Makanan');
        $this->assertSame(['Toko Grosir Jaya', 'UD Berkah Pangan'], array_column($c->instance()->suppliers, 'name'));
        $c->set('sort', 'hutang');
        $this->assertSame('UD Berkah Pangan', $c->instance()->suppliers[0]['name']);

        $c->call('resetFilters')->set('search', '3300');
        $this->assertSame(['UD Berkah Pangan'], array_column($c->instance()->suppliers, 'name'));
        $c->set('search', 'tidak ada');
        $c->assertSee('Tidak ada supplier yang cocok.');
    }

    public function test_detail_panel_with_orders_payables_and_contact_links(): void
    {
        Livewire::test(Suppliers::class)->call('show', 2)
            ->assertSee('Antar setiap Senin')->assertSee('https://wa.me/6281233004411', false)
            ->assertSee('PO-')->assertSee('Hutang berjalan')
            ->call('closeDetail')->assertSet('selectedId', null);

        $this->assertNull(Suppliers::whatsapp('0341-555-120'));
        $this->assertSame('https://wa.me/6281322117788', Suppliers::whatsapp('0813-2211-7788'));
    }
}
