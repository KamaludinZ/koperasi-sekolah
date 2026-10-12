<?php

namespace Tests\Feature\Transaksi;

use App\Models\Member;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDetailEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_returns_full_detail_with_items_and_links(): void
    {
        $tx = Transaction::factory()->qris()
            ->withItems([$this->pid('MNM001') => 2, $this->pid('ATK001') => 1])
            ->at('2026-10-12 09:15:00')
            ->create(['user_id' => User::factory()->create(['name' => 'Pak Dedi'])->id]);

        $this->getJson("/transaksi/{$tx->id}/rincian")
            ->assertOk()
            ->assertJsonPath('data.invoice_number', $tx->invoice_number)
            ->assertJsonPath('data.cashier', 'Pak Dedi')
            ->assertJsonPath('data.payment_method_label', 'QRIS / Transfer')
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.member', null)
            ->assertJsonPath('data.links.receipt', route('kasir.transactions.receipt', $tx))
            ->assertJsonStructure(['data' => ['items' => [['product_code', 'product_name', 'price', 'quantity', 'subtotal']], 'newer_id', 'older_id'], 'profile' => ['name']]);
    }

    public function test_keeps_item_snapshot_when_product_deleted(): void
    {
        $tx = Transaction::factory()->withItems([$this->pid('MNM001') => 1])->create();
        $name = $tx->items()->first()->product_name;
        Product::whereKey($this->pid('MNM001'))->delete();

        $this->getJson("/transaksi/{$tx->id}/rincian")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', $name)
            ->assertJsonPath('data.items.0.product_code', null);
    }

    public function test_neighbors_follow_list_order(): void
    {
        $old = Transaction::factory()->withItems()->at('2026-10-10 08:00:00')->create();
        $a = Transaction::factory()->withItems()->at('2026-10-11 08:00:00')->create();
        $b = Transaction::factory()->withItems()->at('2026-10-11 08:00:00')->create(); // detik sama, id lebih besar
        $new = Transaction::factory()->withItems()->at('2026-10-12 08:00:00')->create();

        $this->getJson("/transaksi/{$a->id}/rincian")
            ->assertJsonPath('data.newer_id', $b->id)
            ->assertJsonPath('data.older_id', $old->id);
        $this->getJson("/transaksi/{$b->id}/rincian")
            ->assertJsonPath('data.newer_id', $new->id)
            ->assertJsonPath('data.older_id', $a->id);
        $this->getJson("/transaksi/{$new->id}/rincian")->assertJsonPath('data.newer_id', null);
        $this->getJson("/transaksi/{$old->id}/rincian")->assertJsonPath('data.older_id', null);
    }

    public function test_shows_member_name(): void
    {
        $m = Member::factory()->create(['name' => 'Andi Pratama', 'class' => '7A']);
        $tx = Transaction::factory()->withItems()->create(['member_id' => $m->id]);

        $this->getJson("/transaksi/{$tx->id}/rincian")->assertJsonPath('data.member', 'Andi Pratama (7A)');
        $this->getJson('/transaksi/daftar')->assertJsonPath('data.0.member', 'Andi Pratama (7A)')->assertJsonPath('data.0.member_id', $m->id);
    }

    public function test_unknown_transaction_is_404(): void
    {
        $this->getJson('/transaksi/999999/rincian')->assertNotFound();
    }
}
