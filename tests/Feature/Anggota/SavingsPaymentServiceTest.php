<?php

namespace Tests\Feature\Anggota;

use App\Models\ActivityLog;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Product;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\ReceiptPresenter;
use App\Services\SavingsPaymentService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SavingsPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function checkout(int $memberId, int $qty = 2): Transaction
    {
        return app(CheckoutService::class)->checkout([$this->pid('ATK001') => $qty], 'tabungan', null, auth()->id(), $memberId); // Rp4.000/pcs
    }

    public function test_checkout_with_savings_deducts_balance_and_links_entry(): void
    {
        $m = Member::factory()->create(['balance' => 20000]);
        $tx = $this->checkout($m->id);

        $this->assertSame(['tabungan', $m->id, 8000, 8000, 0], [$tx->payment_method, $tx->member_id, (int) $tx->total, (int) $tx->paid, (int) $tx->change]);
        $this->assertSame(12000.0, (float) $m->fresh()->balance);
        $entry = SavingsTransaction::sole();
        $this->assertSame(['tarik', 8000, 20000, 12000, 'transaction', $tx->id], [$entry->type, (int) $entry->amount, (int) $entry->balance_before, (int) $entry->balance_after, $entry->source_type, (int) $entry->source_id]);
        $this->assertStringContainsString($tx->invoice_number, $entry->note);
        $this->assertSame($entry->id, app(SavingsPaymentService::class)->forTransaction($tx->id)->id);
        $this->assertTrue(ActivityLog::where('event', 'savings.purchase')->exists());
        $this->assertStringContainsString('Saldo tabungan', ActivityLog::where('event', 'sale.created')->value('description'));

        $receipt = app(ReceiptPresenter::class)->present($tx);
        $this->assertSame([20000, 12000, 'Saldo tabungan'], [$receipt['balance_before'], $receipt['balance_after'], $receipt['payment_method_label']]);
    }

    public function test_insufficient_balance_rolls_back_whole_sale(): void
    {
        $m = Member::factory()->create(['balance' => 5000]);
        $stock = Product::find($this->pid('ATK001'))->stock;

        try {
            $this->checkout($m->id);
            $this->fail('Seharusnya gagal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Saldo tabungan kurang Rp3.000', $e->errors()['member_id'][0]);
        }
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, SavingsTransaction::count());
        $this->assertSame($stock, Product::find($this->pid('ATK001'))->stock);
        $this->assertSame(5000.0, (float) $m->fresh()->balance);
    }

    public function test_requires_active_member(): void
    {
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->checkout([$this->pid('ATK001') => 1], 'tabungan', null);
    }

    public function test_inactive_member_rejected(): void
    {
        $m = Member::factory()->create(['balance' => 50000, 'is_active' => false]);
        try {
            $this->checkout($m->id);
            $this->fail('Seharusnya gagal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
        }
        $this->assertSame(0, Transaction::count());
    }

    public function test_bon_not_yet_accepted_by_checkout(): void
    {
        $m = Member::factory()->create();
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->checkout([$this->pid('ATK001') => 1], 'bon', null, null, $m->id);
    }

    public function test_refund_returns_balance_even_for_inactive_member(): void
    {
        $m = Member::factory()->create(['balance' => 1000, 'is_active' => false]);
        $entry = app(SavingsPaymentService::class)->refund($m->id, 4000, 'return', 9, 'Retur RTR-1');
        $this->assertSame(['setor', 1000, 5000, 'return', 9], [$entry->type, (int) $entry->balance_before, (int) $entry->balance_after, $entry->source_type, (int) $entry->source_id]);
        $this->assertSame(5000.0, (float) $m->fresh()->balance);
        $this->assertTrue(ActivityLog::where('event', 'savings.refund')->exists());

        $this->expectException(ValidationException::class);
        app(SavingsPaymentService::class)->refund($m->id, 0, 'return', 9, 'x');
    }

    public function test_savings_sales_not_counted_as_cash_in_shift(): void
    {
        $shift = CashShift::factory()->create();
        $m = Member::factory()->create(['balance' => 20000]);
        $tx = $this->checkout($m->id);
        $this->assertSame($shift->id, $tx->cash_shift_id);
        $this->assertSame(0, $shift->fresh()->cashSales());
    }

    public function test_checkout_endpoint_accepts_savings(): void
    {
        $m = Member::factory()->create(['balance' => 20000]);
        $this->loginAs('kasir@koperasi.test');
        $this->postJson(route('kasir.checkout'), ['items' => [['product_id' => $this->pid('ATK001'), 'quantity' => 1]], 'payment_method' => 'tabungan'])
            ->assertStatus(422)->assertJsonValidationErrors('member_id');
        $this->postJson(route('kasir.checkout'), ['items' => [['product_id' => $this->pid('ATK001'), 'quantity' => 1]], 'payment_method' => 'tabungan', 'member_id' => $m->id])
            ->assertCreated()->assertJsonPath('data.payment_method', 'tabungan');
        $this->assertSame(16000.0, (float) $m->fresh()->balance);
    }
}
