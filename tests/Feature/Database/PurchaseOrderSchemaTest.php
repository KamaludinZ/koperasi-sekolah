<?php

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Database\Seeders\ProductSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
        $this->seed([ProductSeeder::class, UserSeeder::class, SupplierSeeder::class, PurchaseOrderSeeder::class]);
    }

    public function test_seeded_orders_numbers_totals_and_progress(): void
    {
        $this->assertSame(6, PurchaseOrder::count());
        $this->assertSame(['PO-202610-0001', 'PO-202610-0006'], [PurchaseOrder::min('number'), PurchaseOrder::max('number')]);
        $this->assertSame('PO-202610-0007', PurchaseOrder::nextNumber());

        $partial = PurchaseOrder::with('items')->where('status', 'sebagian')->first();
        $this->assertSame(260, $partial->items->sum('quantity'));
        $this->assertSame(182, $partial->items->sum('received_quantity'));
        $this->assertSame($partial->items->sum(fn ($i) => $i->subtotal()), $partial->total);
        $this->assertTrue($partial->isLate());
        $this->assertSame(2, PurchaseOrder::waiting()->count());
        $this->assertSame('Harga naik, pindah supplier.', PurchaseOrder::where('status', 'dibatalkan')->value('cancel_reason'));
    }

    public function test_item_snapshot_survives_product_removal_and_supplier_is_protected(): void
    {
        $item = PurchaseOrder::where('status', 'diterima')->first()->items()->first();
        Product::withTrashed()->whereKey($item->product_id)->first()->forceDelete();
        $this->assertNull($item->fresh()->product_id);
        $this->assertNotSame('', $item->fresh()->product_name);

        $this->expectException(QueryException::class);
        DB::table('suppliers')->where('id', PurchaseOrder::first()->supplier_id)->delete();
    }

    public function test_pgsql_checks_quantities(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint khusus PostgreSQL.');
        }
        $item = PurchaseOrder::first()->items()->first();
        $this->expectException(QueryException::class);
        $item->update(['received_quantity' => $item->quantity + 1]);
    }
}
