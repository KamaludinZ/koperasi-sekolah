<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\Index;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProcurementHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
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

        $this->assertSame(3, $s['open_orders']);                       // draf, dipesan, sebagian
        $this->assertSame(980_000 + 1_120_000 + 1_350_000, $s['open_value']);
        $this->assertSame(120 + 80, $s['awaiting_qty']);                // draf tidak dihitung
        $this->assertSame(860_000 + 640_000 + 610_000, $s['payable']);
        $this->assertSame(1, $s['overdue']);
        $this->assertSame(1, $s['due_soon']);
        $this->assertSame(3, $s['suppliers']);

        $restock = collect($c->instance()->restock);
        $this->assertSame(Product::whereColumn('stock', '<=', 'min_stock')->where('is_active', true)->count(), $restock->count());
        $penggaris = $restock->firstWhere('code', 'ATK005');
        $this->assertSame([0, 10, 'CV Sinar Ilmu'], [$penggaris['stock'], $penggaris['suggested'], $penggaris['supplier']]);
        $this->assertSame('ATK005', $restock->first()['code']); // paling kurang dulu

        $c->assertSee('lewat 1 hari')->assertSee('Terlambat')->assertSee('Belum dikirim ke supplier');
    }
}
