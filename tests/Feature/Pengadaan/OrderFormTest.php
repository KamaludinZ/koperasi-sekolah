<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\OrderForm;
use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
        $this->seed([ProductSeeder::class, SupplierSeeder::class]);
    }

    private function sid(string $name): string
    {
        return (string) Supplier::where('name', $name)->value('id');
    }

    public function test_page_and_links(): void
    {
        $this->get('/pengadaan/pesanan/baru')->assertOk()->assertSee('Pesanan pembelian baru')->assertDontSee('Toko Grosir Jaya');
        $this->get('/pengadaan/pesanan')->assertSee(route('pengadaan.pesanan.buat'));
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/pesanan/baru')->assertForbidden();
    }

    public function test_search_add_fill_low_stock_and_total(): void
    {
        $c = Livewire::test(OrderForm::class)->call('fillLowStock')->assertHasErrors('supplierId');
        $c->set('supplierId', $this->sid('CV Sinar Ilmu'))->call('fillLowStock');
        $this->assertSame(['ATK003', 'ATK005'], array_column($c->get('lines'), 'code'));
        $this->assertSame([18, 10], array_column($c->get('lines'), 'qty'));

        $c->set('search', 'pulp');
        $this->assertSame(['ATK002'], array_column($c->instance()->results, 'code'));
        $c->call('addProduct', $this->pid('ATK002'), 50)->assertSet('search', '');
        $c->call('addProduct', $this->pid('ATK002'));
        $this->assertCount(3, $c->get('lines'));
        $this->assertSame(18 * 2000 + 10 * 2800 + 50 * 2400, $c->instance()->total);
        $c->call('removeLine', 1);
        $this->assertSame(['ATK003', 'ATK002'], array_column($c->get('lines'), 'code'));
    }

    public function test_validation_and_save_as_ordered(): void
    {
        $c = Livewire::test(OrderForm::class)->call('save', 'dipesan')
            ->assertHasErrors(['supplierId' => 'required', 'lines' => 'required'])->assertDispatched('toast', type: 'error');
        $c->set('supplierId', $this->sid('Toko Grosir Jaya'))->call('save')->assertHasErrors('supplierId');
        $c->set('supplierId', $this->sid('UD Berkah Pangan'))->call('addProduct', $this->pid('MKN003'))
            ->set('lines.0.qty', 0)->set('expectedAt', '2026-10-01')->call('save')->assertHasErrors(['lines.0.qty', 'expectedAt']);

        $c->set('lines.0.qty', 24)->set('lines.0.cost', 2500)->set('expectedAt', '2026-10-17')->set('note', 'Antar pagi')
            ->call('save', 'dipesan')->assertHasNoErrors()->assertRedirect(route('pengadaan.pesanan'));

        $order = PurchaseOrder::with('items')->sole();
        $this->assertSame(['PO-202610-0001', 'dipesan', 24, 60_000, 7, 'Antar pagi'],
            [$order->number, $order->status, $order->items->sum('quantity'), $order->total, $order->terms_days, $order->note]);
        $this->assertSame(['MKN003', 'Keripik Singkong', 2500], [$order->items[0]->product_code, $order->items[0]->product_name, $order->items[0]->cost]);
        $this->assertNotNull($order->sent_at);
        $this->assertSame(60_000, ActivityLog::where('event', 'purchase.ordered')->value('amount'));
    }
}
