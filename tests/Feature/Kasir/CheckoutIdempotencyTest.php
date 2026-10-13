<?php

namespace Tests\Feature\Kasir;

use App\Models\Product;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function body(): array
    {
        return ['items' => [['product_id' => $this->pid('ATK001'), 'quantity' => 2]], 'payment_method' => 'tunai', 'paid' => 10000];
    }

    public function test_same_key_returns_same_transaction_without_double_stock(): void
    {
        $stock = Product::find($this->pid('ATK001'))->stock;
        $first = $this->postJson(route('kasir.checkout'), $this->body(), ['Idempotency-Key' => 'kasir1-abc'])->assertCreated()->assertJsonPath('replayed', false);
        $again = $this->postJson(route('kasir.checkout'), $this->body(), ['Idempotency-Key' => 'kasir1-abc'])->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, Transaction::count());
        $this->assertSame($stock - 2, Product::find($this->pid('ATK001'))->stock);
        $this->assertSame('kasir1-abc', Transaction::sole()->client_ref);
    }

    public function test_body_client_ref_and_without_key(): void
    {
        $this->postJson(route('kasir.checkout'), [...$this->body(), 'client_ref' => 'ref-1'])->assertCreated();
        $this->postJson(route('kasir.checkout'), [...$this->body(), 'client_ref' => 'ref-1'])->assertOk();
        $this->postJson(route('kasir.checkout'), $this->body())->assertCreated();
        $this->postJson(route('kasir.checkout'), $this->body())->assertCreated(); // tanpa kunci: dua penjualan
        $this->assertSame(3, Transaction::count());
    }

    public function test_invalid_key_rejected(): void
    {
        $this->postJson(route('kasir.checkout'), $this->body(), ['Idempotency-Key' => 'bad key!'])->assertStatus(422);
        $this->postJson(route('kasir.checkout'), $this->body(), ['Idempotency-Key' => str_repeat('a', 65)])->assertStatus(422);
        $this->assertSame(0, Transaction::count());
    }
}
