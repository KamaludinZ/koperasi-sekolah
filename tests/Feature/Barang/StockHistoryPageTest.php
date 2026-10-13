<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\StockHistory;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\StockService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $stock = app(StockService::class);
        $a = Product::find($this->pid('ATK001'));
        $stock->increase($a, 10, 'Penerimaan PO-1', auth()->id());
        app(CheckoutService::class)->checkout([$a->id => 3], 'tunai', 20000, auth()->id());
        $stock->adjustTo(Product::find($this->pid('ATK002')), 0, 'Opname: rusak', auth()->id());
    }

    public function test_page_in_nav_and_linked_from_list(): void
    {
        $this->get('/barang')->assertOk()->assertSee(route('barang.riwayat-stok'))->assertSee('Riwayat');
        $this->get('/barang/riwayat-stok')->assertOk()->assertSee('Penerimaan PO-1')->assertSee('Opname: rusak')->assertSee('Penjualan INV-');
    }

    public function test_summary_and_filters(): void
    {
        $c = Livewire::test(StockHistory::class);
        $s = $c->instance()->summary;
        $this->assertSame(3, $s['count']);
        $this->assertSame(10, $s['in']);
        $this->assertGreaterThanOrEqual(3, $s['out']);

        $c->set('type', 'keluar');
        $this->assertSame(['keluar'], $c->instance()->movements->pluck('type')->unique()->values()->all());
        $c->set('type', '')->call('chooseProduct', $this->pid('ATK001'));
        $this->assertSame([$this->pid('ATK001')], $c->instance()->movements->pluck('product_id')->unique()->values()->all());
        $c->assertSee('Semua barang')->call('chooseProduct', null)->assertSet('productId', null);

        $c->set('productSearch', 'atk00');
        $this->assertNotEmpty($c->instance()->productResults);
        $c->call('setPreset', 'kemarin');
        $this->assertSame(0, $c->instance()->summary['count']);
        $c->assertSee('Tidak ada pergerakan stok pada filter ini.');
    }

    public function test_query_string_preselects_product(): void
    {
        Livewire::withQueryParams(['barang' => $this->pid('ATK002'), 'periode' => 'semua'])->test(StockHistory::class)
            ->assertSet('productId', $this->pid('ATK002'))->assertSee('Opname: rusak')->assertDontSee('Penerimaan PO-1');
    }
}
