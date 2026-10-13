<?php

namespace Tests\Feature\Barang;

use App\Livewire\Barang\Restock;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\RestockAdvisor;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestockPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        Product::query()->update(['stock' => 100, 'min_stock' => 5]);
        // ATK001: terjual 60 dalam 30 hari = 2/hari, stok 10 → habis 5 hari.
        Product::where('code', 'ATK001')->update(['stock' => 10]);
        Transaction::factory()->withItems([$this->pid('ATK001') => 60])->at(now()->subDays(3)->toDateTimeString())->create();
        // Transaksi batal tidak dihitung.
        Transaction::factory()->withItems([$this->pid('ATK001') => 90])->at(now()->subDays(2)->toDateTimeString())->create(['voided_at' => now()]);
        // ATK002: tanpa penjualan, stok 3 ≤ min 5 → aturan lama 2×min − stok = 7.
        Product::where('code', 'ATK002')->update(['stock' => 3]);
    }

    public function test_velocity_based_suggestion(): void
    {
        $rows = collect(app(RestockAdvisor::class)->suggestions(14))->keyBy('code');
        $a = $rows['ATK001'];
        $this->assertSame([60, 2.0, 5, 'segera'], [$a['sold'], $a['per_day'], $a['days_left'], $a['level']]);
        $this->assertSame(2 * 14 + 5 - 10, $a['suggested']);
        $this->assertSame([7, 'segera', null], [$rows['ATK002']['suggested'], $rows['ATK002']['level'], $rows['ATK002']['days_left']]);
        $this->assertSame(2 * 7 + 5 - 10, collect(app(RestockAdvisor::class)->suggestions(7))->firstWhere('code', 'ATK001')['suggested']);
        $this->assertArrayNotHasKey('ATK003', $rows->all()); // stok aman tidak ditampilkan
        $this->assertTrue(collect(app(RestockAdvisor::class)->suggestions(14, all: true))->contains('code', 'ATK003'));
    }

    public function test_page_and_horizon(): void
    {
        $this->get('/barang')->assertSee(route('barang.saran-restok'));
        $this->get('/barang/saran-restok')->assertOk()->assertSee('Segera pesan')->assertSee('5 hari')->assertSee('Buat pesanan pembelian');
        $c = Livewire::test(Restock::class)->call('setHorizon', 30);
        $this->assertSame(2 * 30 + 5 - 10, collect($c->instance()->rows)->firstWhere('code', 'ATK001')['suggested']);
        $this->assertGreaterThan(0, $c->instance()->totals['cost']);
        $c->call('setHorizon', 99)->assertSet('horizon', 14);
    }
}
