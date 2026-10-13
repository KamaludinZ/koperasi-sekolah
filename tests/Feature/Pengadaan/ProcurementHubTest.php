<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Index;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProcurementHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    public function test_menu_and_access(): void
    {
        $this->get('/pengadaan')->assertOk()->assertSee('Pengadaan Barang')->assertSee('UD Berkah Pangan')->assertSee('Perlu dipesan');
        $this->get('/kasir')->assertSee(route('pengadaan'), false);

        $this->loginAs('pengurus@koperasi.test');
        $this->get('/pengadaan')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan')->assertForbidden();
        $this->get('/kasir')->assertDontSee(route('pengadaan'), false);
    }

    public function test_summary_from_orders_payables_and_low_stock(): void
    {
        $c = Livewire::test(Index::class);
        $s = $c->instance()->summary;

        $open = \App\Models\PurchaseOrder::whereIn('status', ['draf', 'dipesan', 'sebagian'])->get();
        $this->assertSame(3, $s['open_orders']);                       // draf, dipesan, sebagian
        $this->assertSame($open->sum('total'), $s['open_value']);
        $this->assertSame(120 + 78, $s['awaiting_qty']);                // draf tidak dihitung
        $this->assertSame(259_200 + 166_400, $s['payable']);
        $this->assertSame(0, $s['overdue']);
        $this->assertSame(1, $s['due_soon']);
        $this->assertSame(3, $s['suppliers']);

        $restock = collect($c->instance()->restock);
        $this->assertSame(Product::whereColumn('stock', '<=', 'min_stock')->where('is_active', true)->count(), $restock->count());
        $penggaris = $restock->firstWhere('code', 'ATK005');
        $this->assertSame([0, 10, 'CV Sinar Ilmu'], [$penggaris['stock'], $penggaris['suggested'], $penggaris['supplier']]);
        $this->assertSame('ATK005', $restock->first()['code']); // paling kurang dulu

        $c->assertSee('jatuh tempo 1 hari lagi')->assertSee('Terlambat')->assertSee('Belum dikirim ke supplier');
    }
}
