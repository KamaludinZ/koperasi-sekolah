<?php

namespace Tests\Feature\Portal;

use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Product;
use App\Models\SavingsTransaction;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use App\Services\OrderService;
use App\Services\SavingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderRollbackTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function tearDown(): void
    {
        OrderStatusLog::flushEventListeners(); // lepas simulasi gangguan
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->andi = Member::factory()->create(['password' => 'rahasia123', 'pin' => '246810']);
        app(SavingsService::class)->deposit($this->andi, 20000);
    }

    /** Gagal setelah stok & saldo terpotong (saat mencatat log status) → semuanya dibatalkan. */
    public function test_failure_after_deduction_rolls_everything_back(): void
    {
        $stock = Product::find($this->pid('ATK001'))->stock;
        $entries = SavingsTransaction::count();
        OrderStatusLog::creating(fn () => throw new \RuntimeException('Gangguan simulasi'));

        try {
            app(OrderService::class)->place($this->andi, [$this->pid('ATK001') => 2], ['fulfillment' => 'ambil', 'payment_method' => 'tabungan']);
            $this->fail('Harus gagal');
        } catch (\RuntimeException $e) {
            $this->assertSame('Gangguan simulasi', $e->getMessage());
        }

        $this->assertSame(0, Order::count());
        $this->assertSame($stock, Product::find($this->pid('ATK001'))->stock);
        $this->assertEquals(20000, (float) $this->andi->fresh()->balance);
        $this->assertSame($entries, SavingsTransaction::count());
        $this->assertSame(0, \App\Models\ActivityLog::where('event', 'order.placed')->count());
    }

    public function test_simpanan_failure_rolls_back_and_staff_cancel_refunds(): void
    {
        $sukarela = SavingType::where('code', 'SUKARELA')->first();
        app(MemberSavingService::class)->deposit($this->andi, $sukarela, 10000);
        $balance = fn () => (int) $this->andi->savingAccounts()->where('saving_type_id', $sukarela->id)->value('balance');

        OrderStatusLog::creating(fn () => throw new \RuntimeException('Gangguan simulasi'));
        try {
            app(OrderService::class)->place($this->andi, [$this->pid('ATK002') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'simpanan', 'saving_type_id' => $sukarela->id]);
        } catch (\RuntimeException) {
        }
        $this->assertSame(10000, $balance());
        $this->assertSame(0, MemberSavingEntry::where('type', 'potong')->count());
        OrderStatusLog::flushEventListeners();

        $o = app(OrderService::class)->place($this->andi, [$this->pid('ATK002') => 1], ['fulfillment' => 'ambil', 'payment_method' => 'simpanan', 'saving_type_id' => $sukarela->id]);
        $o->update(['status' => 'siap']);
        app(OrderService::class)->cancel($o, 'Barang rusak saat disiapkan', auth()->id(), 'Petugas');
        $this->assertSame(['dibatalkan', 'dikembalikan'], [$o->fresh()->status, $o->fresh()->payment_status]);
        $this->assertSame(10000, $balance());
    }
}
