<?php

namespace Tests\Feature\Transaksi;

use App\Models\Transaction;
use App\Models\TransactionReturn;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReturnSchemaTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->tx = Transaction::factory()->withItems([$this->pid('ATK001') => 2])->create();
    }

    private function request(array $attrs = []): TransactionReturn
    {
        static $n = 0;

        return TransactionReturn::create([
            'number' => 'RTR-T-'.(++$n).'-'.uniqid(), 'kind' => 'retur', 'transaction_id' => $this->tx->id, 'reason_key' => 'rusak', 'reason' => 'Barang rusak/cacat',
            'refund_method' => 'tunai', 'amount' => 4000, 'quantity' => 1, 'requested_at' => now(), ...$attrs,
        ]);
    }

    public function test_tables_and_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('transaction_returns', ['number', 'kind', 'transaction_id', 'status', 'reason_key', 'reason', 'note', 'restock', 'refund_method', 'amount', 'quantity', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_note']));
        $this->assertTrue(Schema::hasColumns('transaction_return_items', ['transaction_return_id', 'transaction_item_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'amount']));
        $this->assertTrue(Schema::hasColumns('transactions', ['voided_at', 'voided_by', 'void_reason', 'returned_amount']));
    }

    public function test_relations_and_scopes(): void
    {
        $item = $this->tx->items()->first();
        $r = $this->request();
        $r->items()->create(['transaction_item_id' => $item->id, 'product_id' => $item->product_id, 'product_name' => $item->product_name, 'quantity' => 1, 'unit_price' => 4000, 'amount' => 4000]);

        $this->assertSame([$r->id], $this->tx->returns()->pluck('id')->all());
        $this->assertSame(1, TransactionReturn::pending()->count());
        $this->assertSame($item->id, $r->items()->first()->transactionItem->id);
        $this->assertTrue($r->isPending());

        $this->assertSame(1, Transaction::notVoided()->count());
        $this->tx->update(['voided_at' => now(), 'void_reason' => 'Salah input']);
        $this->assertTrue($this->tx->fresh()->isVoided());
        $this->assertSame(0, Transaction::notVoided()->count());
    }

    public function test_one_pending_and_one_approved_void_per_transaction(): void
    {
        $this->request();
        $this->assertThrows(fn () => DB::transaction(fn () => $this->request()), QueryException::class);

        $this->request(['status' => 'ditolak', 'decided_at' => now()]); // ditolak boleh lebih dari satu
        $this->request(['kind' => 'batal', 'status' => 'disetujui', 'decided_at' => now()]);
        $this->assertThrows(fn () => DB::transaction(fn () => $this->request(['kind' => 'batal', 'status' => 'disetujui', 'decided_at' => now()])), QueryException::class);
        $this->assertSame(3, TransactionReturn::count());
    }

    public function test_pgsql_checks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya di PostgreSQL.');
        }
        foreach ([
            ['kind' => 'tukar'],
            ['status' => 'aneh'],
            ['refund_method' => 'kripto'],
            ['quantity' => 0],
            ['status' => 'disetujui'], // keputusan tanpa decided_at
        ] as $i => $bad) {
            $this->assertThrows(fn () => DB::transaction(fn () => $this->request($bad)), QueryException::class, null, "case $i");
        }
        $this->assertThrows(fn () => DB::transaction(fn () => $this->tx->update(['returned_amount' => (int) $this->tx->total + 1])), QueryException::class);
    }
}
