<?php

namespace Tests\Feature\Portal;

use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Services\SavingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->andi = Member::factory()->create(['password' => 'rahasia123', 'pin' => '246810']);
        $this->actingAsMember($this->andi);
    }

    private function payload(array $o = []): array
    {
        return ['items' => [['product_id' => $this->pid('ATK001'), 'quantity' => 2]], 'fulfillment' => 'ambil', 'payment_method' => 'tunai', ...$o];
    }

    private function token(): string
    {
        return $this->postJson('/portal/pin/verifikasi', ['pin' => '246810'])->json('data.token');
    }

    public function test_requires_verified_pin_token(): void
    {
        $stock = Product::find($this->pid('ATK001'))->stock;
        $this->postJson('/portal/pesanan', $this->payload())->assertForbidden()->assertJsonValidationErrors('pin');
        $this->postJson('/portal/pesanan', $this->payload(), ['X-Pin-Token' => 'palsu'])->assertForbidden();
        $this->assertSame(0, Order::count());
        $this->assertSame($stock, Product::find($this->pid('ATK001'))->stock);

        $t = $this->token();
        $this->postJson('/portal/pesanan', $this->payload(), ['X-Pin-Token' => $t])->assertCreated()->assertJsonPath('data.total', 8000);
        $this->postJson('/portal/pesanan', $this->payload(), ['X-Pin-Token' => $t])->assertForbidden(); // token sekali pakai
        $this->assertSame(1, Order::count());
    }

    public function test_idempotency_and_balance_payment(): void
    {
        app(SavingsService::class)->deposit($this->andi, 20000);
        $h = ['X-Pin-Token' => $this->token(), 'Idempotency-Key' => 'pesan-1'];
        $id = $this->postJson('/portal/pesanan', $this->payload(['payment_method' => 'tabungan']), $h)->assertCreated()
            ->assertJsonPath('data.payment_status', 'lunas')->json('data.id');
        $this->postJson('/portal/pesanan', $this->payload(['payment_method' => 'tabungan']), ['Idempotency-Key' => 'pesan-1'])->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true);
        $this->assertEquals(12000, (float) $this->andi->fresh()->balance);

        $this->postJson('/portal/pesanan', $this->payload(['payment_method' => 'utang']), ['X-Pin-Token' => $this->token()])->assertJsonValidationErrors('payment_method');
    }

    public function test_requires_portal_login(): void
    {
        auth('member')->logout();
        $this->postJson('/portal/pesanan', $this->payload())->assertUnauthorized();
    }

    public function test_pay_pending_order_with_balance(): void
    {
        $id = $this->postJson('/portal/pesanan', $this->payload(), ['X-Pin-Token' => $this->token()])->assertCreated()->json('data.id');
        $this->postJson("/portal/pesanan/{$id}/bayar-saldo", [])->assertForbidden(); // tanpa PIN
        $this->postJson("/portal/pesanan/{$id}/bayar-saldo", [], ['X-Pin-Token' => $this->token()])->assertJsonValidationErrors('payment_method'); // saldo 0

        app(SavingsService::class)->deposit($this->andi, 10000);
        $this->postJson("/portal/pesanan/{$id}/bayar-saldo", [], ['X-Pin-Token' => $this->token()])->assertOk()
            ->assertJsonPath('data.payment_status', 'lunas')->assertJsonPath('data.payment_method', 'tabungan')->assertJsonPath('data.deduction.after', 2000);
        $this->assertEquals(2000, (float) $this->andi->fresh()->balance);
        $this->postJson("/portal/pesanan/{$id}/bayar-saldo", [], ['X-Pin-Token' => $this->token()])->assertJsonValidationErrors('order');

        $other = \App\Models\Member::factory()->create(['password' => 'rahasia123', 'pin' => '111222']);
        $foreign = app(\App\Services\OrderService::class)->place($other, [$this->pid('ATK002') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tunai']);
        $this->postJson("/portal/pesanan/{$foreign->id}/bayar-saldo", [], ['X-Pin-Token' => $this->token()])->assertNotFound();
    }

    public function test_deduction_history_endpoint(): void
    {
        app(SavingsService::class)->deposit($this->andi, 20000);
        $svc = app(\App\Services\OrderService::class);
        $a = $svc->place($this->andi, [$this->pid('ATK001') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tabungan']);
        $b = $svc->place($this->andi, [$this->pid('ATK002') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'tabungan']);
        $svc->cancel($b, 'Tidak jadi');

        $this->getJson('/portal/potong-saldo')->assertOk()
            ->assertJsonPath('meta', ['deducted' => 7000, 'refunded' => 3000, 'count' => 2])
            ->assertJsonPath('data.0.order.code', $b->code)->assertJsonPath('data.0.refunded', true)
            ->assertJsonPath('data.1.order.id', $a->id)->assertJsonPath('data.1.source', 'Tabungan');
        $this->getJson('/portal/potong-saldo?periode=minggu')->assertJsonValidationErrors('periode');
        auth('member')->logout();
        $this->getJson('/portal/potong-saldo')->assertUnauthorized();
    }
}
