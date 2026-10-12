<?php

namespace Tests\Feature\Kasir;

use App\Models\Product;
use App\Services\CheckoutService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionReceiptTest extends TestCase
{
    use RefreshDatabase;

    private int $transactionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);

        $buku = Product::where('code', 'ATK001')->value('id');
        $this->transactionId = app(CheckoutService::class)->checkout([$buku => 3], 'tunai', 15000)->id;
    }

    public function test_json_detail_contains_receipt_data(): void
    {
        $this->getJson("/kasir/transaksi/{$this->transactionId}")
            ->assertOk()
            ->assertJsonPath('data.total', 12000)
            ->assertJsonPath('data.change', 3000)
            ->assertJsonPath('data.payment_method_label', 'Tunai')
            ->assertJsonPath('data.items.0.product_name', 'Buku Tulis 38 Lembar')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonStructure(['data' => ['invoice_number', 'created_at', 'cashier'], 'profile' => ['name', 'address', 'receipt_footer']]);
    }

    public function test_printable_receipt_page(): void
    {
        $this->get("/kasir/transaksi/{$this->transactionId}/struk")
            ->assertOk()
            ->assertSee('print-area', false)
            ->assertSee('3 x 4.000')
            ->assertSee('window.print()', false);

        $this->get("/kasir/transaksi/{$this->transactionId}/struk?cetak=0")
            ->assertDontSee("addEventListener('load'", false);
    }

    public function test_receipt_keeps_snapshot_after_product_changes(): void
    {
        Product::where('code', 'ATK001')->update(['name' => 'Nama Baru', 'price' => 9999]);

        $this->getJson("/kasir/transaksi/{$this->transactionId}")
            ->assertJsonPath('data.items.0.product_name', 'Buku Tulis 38 Lembar')
            ->assertJsonPath('data.items.0.price', 4000);
    }

    public function test_missing_transaction_returns_404(): void
    {
        $this->getJson('/kasir/transaksi/999999')->assertNotFound();
        $this->get('/kasir/transaksi/abc/struk')->assertNotFound();
    }
}
