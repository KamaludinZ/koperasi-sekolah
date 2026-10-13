<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Suppliers;
use App\Models\Supplier;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    private function id(string $name): int
    {
        return Supplier::where('name', $name)->value('id');
    }

    public function test_access_and_nav(): void
    {
        $this->get('/pengadaan/supplier')->assertOk()->assertSee('CV Sinar Ilmu')->assertDontSee('Toko Grosir Jaya')->assertDontSee('Data contoh');
        $this->get('/pengadaan')->assertSee(route('pengadaan.supplier'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/pengadaan/supplier')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/supplier')->assertForbidden();
    }

    public function test_filters_sort_and_summary_from_real_data(): void
    {
        $c = Livewire::test(Suppliers::class);
        $s = $c->instance()->summary;
        $this->assertSame([3, 1, 3], [$s['active'], $s['inactive'], $s['credit']]);
        $sinar = collect($c->instance()->all)->firstWhere('name', 'CV Sinar Ilmu');
        $this->assertSame(696_000 + 426_400, $sinar['purchases_90d']);
        $this->assertSame(426_400 - 260_000, $sinar['open_payable']);
        $this->assertSame(['CV Sinar Ilmu', 'Konveksi Amanah', 'UD Berkah Pangan'], array_column($c->instance()->suppliers, 'name'));

        $c->call('setStatus', 'semua')->set('category', 'Makanan');
        $this->assertSame(['Toko Grosir Jaya', 'UD Berkah Pangan'], array_column($c->instance()->suppliers, 'name'));
        $c->set('sort', 'hutang');
        $this->assertSame('UD Berkah Pangan', $c->instance()->suppliers[0]['name']);
        $c->call('resetFilters')->set('search', '3300');
        $this->assertSame(['UD Berkah Pangan'], array_column($c->instance()->suppliers, 'name'));
    }

    public function test_detail_panel_and_delete_rules(): void
    {
        $berkah = $this->id('UD Berkah Pangan');
        Livewire::test(Suppliers::class)->call('show', $berkah)
            ->assertSee('Antar setiap Senin')->assertSee('https://wa.me/6281233004411', false)->assertSee('PO-202610-')
            ->set('confirmingDelete', true)->call('delete')->assertHasErrors('deleteReason')->assertSee('pesanan berjalan');
        $this->assertNotSoftDeleted(Supplier::find($berkah));

        $jaya = $this->id('Toko Grosir Jaya');
        Livewire::test(Suppliers::class)->call('show', $jaya)->set('deleteReason', 'Tidak dipakai')->call('delete')
            ->assertDispatched('toast', type: 'success')->assertSet('selectedId', null);
        $this->assertSoftDeleted(Supplier::withTrashed()->find($jaya));

        $this->assertNull(Suppliers::whatsapp('0341-555-120'));
    }
}
