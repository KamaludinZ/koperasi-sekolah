<?php

namespace Tests\Feature\Transaksi;

use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Transaction> lama → baru */
    private array $txs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $cashier = User::factory()->create(['name' => 'Bu Rina']);
        $this->txs = collect(['2026-10-12 08:00:00', '2026-10-13 08:00:00', '2026-10-14 08:00:00'])
            ->map(fn ($at) => Transaction::factory()->withItems()->at($at)->create(['user_id' => $cashier->id]))
            ->all();
    }

    public function test_list_rows_link_to_detail(): void
    {
        $newest = $this->txs[2];

        $this->get('/transaksi')
            ->assertSee(route('transaksi.show', $newest), false)
            ->assertSee('Lihat detail transaksi '.$newest->invoice_number);
    }

    public function test_detail_page_with_newer_and_older_links(): void
    {
        [$old, $middle, $new] = $this->txs;

        $this->get("/transaksi/{$middle->id}")
            ->assertOk()
            ->assertSee($middle->invoice_number)
            ->assertSee(route('transaksi.show', $new), false)
            ->assertSee(route('transaksi.show', $old), false)
            ->assertSee(route('transaksi'), false);
    }

    public function test_newest_has_no_newer_link_and_unknown_is_404(): void
    {
        $this->get("/transaksi/{$this->txs[2]->id}")->assertOk()->assertDontSee('x-ref="newer"', false);
        $this->get('/transaksi/99999')->assertNotFound();
    }

    public function test_detail_shows_items_payment_and_receipt(): void
    {
        $tx = $this->txs[2];

        $this->get("/transaksi/{$tx->id}")
            ->assertSee('Rincian barang')
            ->assertSee($tx->items()->first()->product_name)
            ->assertSee('Kembalian')
            ->assertSee('print-area', false)
            ->assertSee('Cetak ulang struk')
            ->assertSee('Bu Rina');
    }
}
