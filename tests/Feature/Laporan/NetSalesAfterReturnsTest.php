<?php

namespace Tests\Feature\Laporan;

use App\Models\Transaction;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\SalesReport;
use App\Services\TransactionHistory;
use App\Services\TransactionReturnService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetSalesAfterReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $kept;

    private Transaction $voided;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $cashier = User::where('email', 'kasir@koperasi.test')->first();
        $checkout = app(CheckoutService::class);
        $svc = app(TransactionReturnService::class);

        // Rp4.000/pcs. Transaksi 1: 5 pcs, 2 pcs diretur (layak jual). Transaksi 2: 3 pcs, dibatalkan.
        $this->kept = $checkout->checkout([$this->pid('ATK001') => 5], 'tunai', 20000, $cashier->id);
        $r = $svc->requestReturn($this->kept, [$this->kept->items->first()->id => 2], 'salah_ambil', true, 'tunai', null, $cashier);
        $svc->approve($r, auth()->user());
        $this->voided = $checkout->checkout([$this->pid('ATK001') => 3], 'tunai', 12000, $cashier->id);
        $v = $svc->requestVoid($this->voided, 'ganda', null, $cashier);
        if ($v->isPending()) {
            $svc->approve($v, auth()->user());
        }
    }

    public function test_sales_summary_is_net_of_returns_and_voids(): void
    {
        $s = app(SalesReport::class)->summary(today(), now()->endOfDay());
        $this->assertSame([12000, 1, 3], [$s['revenue'], $s['count'], $s['items']]);
        $cost = (int) $this->kept->items->first()->cost_price;
        $this->assertSame(12000 - 3 * $cost, $s['gross_profit']);

        $top = app(SalesReport::class)->topProducts(today(), now()->endOfDay());
        $this->assertSame(3, $top[0]['quantity']);
        $this->assertSame(12000, $top[0]['revenue']);

        $this->assertSame(12000, collect(app(SalesReport::class)->daily(today(), now()->endOfDay()))->sum('revenue'));
        $this->assertSame(12000, collect(app(SalesReport::class)->chart(today(), now()->endOfDay()))->sum('value'));
    }

    public function test_history_marks_voided_and_returns_with_net_summary(): void
    {
        $sum = app(TransactionHistory::class)->summary(today(), now()->endOfDay());
        $this->assertSame([1, 12000], [$sum['count'], $sum['total']]);

        $this->get('/transaksi')->assertOk()->assertSee('Dibatalkan')->assertSee('retur −Rp8.000');
        $this->get(route('transaksi.show', $this->voided))->assertOk()->assertSee('dibatalkan')->assertDontSee('Retur barang</a>', false);
        $this->get(route('transaksi.show', $this->kept))->assertOk()->assertSee('Ada retur yang disetujui senilai');
        $this->getJson(route('transaksi.detail', $this->voided))->assertJsonPath('data.returned_amount', 12000)->assertJsonPath('data.void_reason', 'Transaksi tercatat ganda');
    }
}
