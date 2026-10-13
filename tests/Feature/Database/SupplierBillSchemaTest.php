<?php

namespace Tests\Feature\Database;

use App\Models\GoodsReceipt;
use App\Models\SupplierBill;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierBillSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, UserSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    public function test_bills_follow_receipts_and_terms(): void
    {
        $this->assertSame(3, SupplierBill::count());
        foreach (SupplierBill::with(['receipt', 'supplier'])->get() as $b) {
            $this->assertSame($b->receipt->total_value, $b->amount);
            $this->assertTrue($b->due_at->equalTo($b->receipt->received_at->copy()->addDays($b->supplier->terms_days)));
        }
        $this->assertSame(['belum' => 1, 'lunas' => 1, 'sebagian' => 1], SupplierBill::query()->toBase()->groupBy('status')->selectRaw('status, COUNT(*) n')->orderBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(2, SupplierBill::open()->count());
    }

    public function test_sync_paid_updates_status(): void
    {
        $bill = SupplierBill::where('status', 'belum')->first();
        $bill->payments()->create(['amount' => 100_000, 'paid_at' => today(), 'method' => 'tunai']);
        $bill->syncPaid();
        $this->assertSame(['sebagian', 100_000, $bill->amount - 100_000], [$bill->status, $bill->paid, $bill->remaining()]);

        $bill->payments()->create(['amount' => $bill->remaining(), 'paid_at' => today(), 'method' => 'transfer', 'reference' => 'TRF-1']);
        $bill->syncPaid();
        $this->assertSame('lunas', $bill->status);
        $this->assertNotNull($bill->paid_off_at);
        $this->assertSame(1, SupplierBill::whereKey($bill->id)->first()->daysLeft()); // diterima 8 Okt + tempo 7 hari
    }

    public function test_one_bill_per_receipt_and_protected_links(): void
    {
        $receipt = GoodsReceipt::whereHas('order', fn ($q) => $q->where('status', 'diterima'))->first();
        try {
            DB::transaction(fn () => SupplierBill::create(['supplier_id' => $receipt->supplier_id, 'purchase_order_id' => $receipt->purchase_order_id,
                'goods_receipt_id' => $receipt->id, 'amount' => 1, 'due_at' => today()]));
            $this->fail('Tagihan kedua untuk penerimaan yang sama seharusnya ditolak.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('supplier_bills')->where('id', SupplierBill::has('payments')->value('id'))->delete();
    }

    public function test_pgsql_rejects_overpayment(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint khusus PostgreSQL.');
        }
        $bill = SupplierBill::first();
        $this->expectException(QueryException::class);
        $bill->forceFill(['paid' => $bill->amount + 1])->save();
    }
}
