<?php

namespace Tests\Feature\Portal;

use App\Models\Member;
use App\Models\Transaction;
use App\Services\OrderService;
use App\Services\SavingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 09:00:00');
        $this->andi = Member::factory()->create(['password' => 'rahasia123', 'pin' => '246810']);
        $this->actingAsMember($this->andi);
    }

    public function test_transactions(): void
    {
        Transaction::factory()->withItems([$this->pid('ATK001') => 2])->at('2026-10-10 08:00:00')->create(['member_id' => $this->andi->id, 'invoice_number' => 'INV-A'])
            ->update(['returned_amount' => 4000]); // setelah total terisi (CHECK returned_amount <= total)
        Transaction::factory()->withItems([$this->pid('ATK002') => 1])->at('2026-10-11 08:00:00')->create(['member_id' => $this->andi->id, 'invoice_number' => 'INV-B', 'voided_at' => now()]);
        Transaction::factory()->withItems([$this->pid('ATK001') => 1])->at('2026-10-12 08:00:00')->create(['invoice_number' => 'INV-ORANG']);

        $this->getJson('/portal/riwayat/data')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.invoice', 'INV-B')->assertJsonPath('data.0.voided', true)
            ->assertJsonPath('data.1.items.0.quantity', 2)->assertJsonPath('meta', ['count' => 1, 'spent' => 4000])
            ->assertJsonMissing(['invoice' => 'INV-ORANG']);
    }

    public function test_orders_and_detail(): void
    {
        app(SavingsService::class)->deposit($this->andi, 20000);
        $svc = app(OrderService::class);
        $o = $svc->place($this->andi, [$this->pid('ATK001') => 2], ['fulfillment' => 'antar', 'delivery_to' => 'Kelas 7A', 'payment_method' => 'tabungan']);
        $c = $svc->place($this->andi, [$this->pid('ATK002') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tunai']);
        $svc->cancel($c, 'Tidak jadi');

        $this->getJson('/portal/pesanan/data')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/portal/pesanan/data?status=dibatalkan')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $c->code);
        $this->getJson("/portal/pesanan/{$o->id}/data")->assertOk()
            ->assertJsonPath('data.delivery_to', 'Kelas 7A')->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.status_logs.0.to', 'menunggu')->assertJsonPath('data.deduction.after', 12000);

        $other = $svc->place(Member::factory()->create(['password' => 'x1234567']), [$this->pid('ATK001') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tunai']);
        $this->getJson("/portal/pesanan/{$other->id}/data")->assertNotFound();
        auth('member')->logout();
        $this->getJson('/portal/pesanan/data')->assertUnauthorized();
    }
}
