<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\ReceiptPdfService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        Storage::fake('local');
    }

    private function checkout(): Transaction
    {
        $id = Product::where('code', 'MKN001')->value('id');

        return app(CheckoutService::class)->checkout([$id => 2], 'tunai', 10000);
    }

    public function test_store_saves_pdf_once_under_dated_folder(): void
    {
        $tx = $this->checkout();
        $service = app(ReceiptPdfService::class);

        $path = $service->store($tx);

        $this->assertSame('receipts/'.$tx->created_at->format('Y/m').'/'.$tx->invoice_number.'.pdf', $path);
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($path));

        Storage::disk('local')->put($path, 'cached');
        $service->store($tx);
        $this->assertSame('cached', Storage::disk('local')->get($path));
    }

    public function test_pdf_endpoint_inline_and_download(): void
    {
        $tx = $this->checkout();

        $this->get("/kasir/transaksi/{$tx->id}/struk.pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "inline; filename=\"struk-{$tx->invoice_number}.pdf\"");

        $this->get("/kasir/transaksi/{$tx->id}/struk.pdf?unduh=1")
            ->assertHeader('Content-Disposition', "attachment; filename=\"struk-{$tx->invoice_number}.pdf\"");
    }

    public function test_cart_payment_stores_receipt_pdf(): void
    {
        $id = Product::where('code', 'MKN001')->value('id');

        Livewire::test(Cart::class)
            ->call('setQty', $id, 1)
            ->call('openPayment')
            ->set('paid', 5000)
            ->call('pay')
            ->assertSee('unduh PDF');

        $tx = Transaction::first();
        Storage::disk('local')->assertExists(app(ReceiptPdfService::class)->path($tx));
    }
}
