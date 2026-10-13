<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Index;
use App\Services\CheckoutService;
use App\Services\ReceiptPresenter;
use App\Services\TransactionHistory;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiscountDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function sale(int $discount, ?string $reason = null)
    {
        return app(CheckoutService::class)->checkout([$this->pid('ATK001') => 5], 'tunai', 20000, auth()->id(), null, $discount, $reason);
    }

    public function test_receipt_data_carries_discount_reason(): void
    {
        $tx = app(ReceiptPresenter::class)->present($this->sale(2000, 'Pelanggan tetap'));
        $this->assertSame([2000, 'Pelanggan tetap', 18000], [$tx['discount'], $tx['discount_reason'], $tx['total']]);

        $plain = app(ReceiptPresenter::class)->present($this->sale(0));
        $this->assertNull($plain['discount_reason']);
    }

    public function test_receipt_and_detail_show_discount_and_savings(): void
    {
        $tx = $this->sale(2000, 'Kegiatan sekolah');

        $this->get(route('kasir.transactions.receipt', $tx))->assertOk()
            ->assertSee('Diskon (Kegiatan sekolah)')->assertSee('Anda hemat Rp2.000');
        $this->get(route('transaksi.show', $tx))->assertOk()
            ->assertSee('Kegiatan sekolah')->assertSee('−Rp2.000');
    }

    public function test_receipt_without_discount_has_no_savings_line(): void
    {
        $this->get(route('kasir.transactions.receipt', $this->sale(0)))->assertOk()->assertDontSee('Anda hemat');
    }

    public function test_history_list_and_summary_show_discount(): void
    {
        $this->sale(2000, 'Pelanggan tetap');
        $this->sale(1500, 'Penyesuaian harga');
        $this->sale(0);

        $s = app(TransactionHistory::class)->summary(today()->startOfDay(), today()->endOfDay());
        $this->assertSame([3500, 2], [$s['discount'], $s['discounted']]);

        Livewire::test(Index::class)
            ->assertSee('diskon −Rp2.000')
            ->assertSee('Diskon diberikan')
            ->assertSee('pada 2 transaksi (67%)');
    }
}
