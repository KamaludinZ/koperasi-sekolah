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
        return app(CheckoutService::class)->checkout([$this->pid('ATK001') => 5], 'tunai', 20000, auth()->id(), null,
            $discount ? ['type' => 'nominal', 'value' => $discount, 'reason' => $reason] : null);
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
            ->assertSee('Diskon manual (Kegiatan sekolah)')->assertSee('Anda hemat Rp2.000');
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

    public function test_receipt_and_detail_list_each_discount_source(): void
    {
        $this->seed(\Database\Seeders\PromoSeeder::class);
        // 5 buku tulis Rp20.000: promo alat tulis 10% (Rp2.000) + kupon GURUHEBAT 10% dari Rp18.000 (Rp1.800) + manual Rp1.000.
        $tx = app(CheckoutService::class)->checkout([$this->pid('ATK001') => 5], 'qris', null, auth()->id(), null,
            ['type' => 'nominal', 'value' => 1000, 'reason' => 'Kemasan penyok'], 'GURUHEBAT');

        $this->assertSame(4800, (int) $tx->discount);
        $receipt = app(ReceiptPresenter::class)->present($tx);
        $this->assertSame([
            ['promo', 'Diskon Alat Tulis Awal Semester', 2000],
            ['kupon', 'Kupon GURUHEBAT', 1800],
            ['manual', 'Diskon manual', 1000],
        ], array_map(fn ($d) => [$d['source'], $d['label'], $d['amount']], $receipt['discounts']));

        $this->get(route('kasir.transactions.receipt', $tx))->assertOk()
            ->assertSee('Diskon Alat Tulis Awal Semester')->assertSee('Kupon GURUHEBAT')->assertSee('Diskon manual (Kemasan penyok)')
            ->assertSee('Anda hemat Rp4.800');
        $this->get(route('transaksi.show', $tx))->assertOk()->assertSee('Promo otomatis')->assertSee('Kupon GURUHEBAT');
        $this->get(route('kasir.transactions.receipt.pdf', $tx))->assertOk();
    }

    public function test_backfill_creates_rows_for_legacy_discounts(): void
    {
        $legacy = \App\Models\Transaction::factory()->create(['total' => 9000, 'discount' => 1000]);
        \App\Models\ActivityLog::insert(['event' => 'sale.created', 'category' => 'transaksi', 'description' => 'x', 'subject_type' => 'transaction',
            'subject_id' => $legacy->id, 'properties' => json_encode(['discount_reason' => 'Pelanggan tetap']), 'created_at' => now()]);
        $plain = \App\Models\Transaction::factory()->create(['total' => 5000]);

        (require database_path('migrations/2026_10_13_000007_backfill_transaction_discounts.php'))->up();
        (require database_path('migrations/2026_10_13_000007_backfill_transaction_discounts.php'))->up(); // aman diulang

        $row = $legacy->discounts()->sole();
        $this->assertSame(['manual', 1000, 'Pelanggan tetap'], [$row->source, (int) $row->amount, $row->reason]);
        $this->assertSame(0, $plain->discounts()->count());
    }
}
