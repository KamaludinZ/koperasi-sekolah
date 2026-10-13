<?php

namespace Tests\Feature\Transaksi;

use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_factory_states_build_items_and_amounts(): void
    {
        $tx = Transaction::factory()->withItems([$this->pid('ATK001') => 3, $this->pid('ATK002') => 1])->create();
        $retur = TransactionReturn::factory()->rejected()->create(['transaction_id' => $tx->id]);
        $this->assertSame([1, 1, 'ditolak', 'Ditolak', 'Retur barang', 'Uang tunai'], [$retur->items()->count(), $retur->quantity, $retur->status, $retur->statusLabel(), $retur->kindLabel(), $retur->refundLabel()]);

        $void = TransactionReturn::factory()->batal()->approved()->create(['transaction_id' => $tx->id]);
        $this->assertSame([2, 4, (int) $tx->total], [$void->items()->count(), $void->quantity, $void->amount]);
        $this->assertTrue($void->isVoid());
        $this->assertSame(['BTL'], [TransactionReturn::PREFIXES['batal']]);
    }

    public function test_relations_and_returned_quantities(): void
    {
        $tx = Transaction::factory()->withItems([$this->pid('ATK001') => 3, $this->pid('ATK002') => 1])->create();
        $user = User::factory()->create();
        $pending = TransactionReturn::factory()->create(['transaction_id' => $tx->id, 'requested_by' => $user->id]);
        TransactionReturn::factory()->rejected()->create(['transaction_id' => $tx->id]); // tidak dihitung

        $first = $tx->items()->orderBy('id')->first();
        $this->assertSame([$first->id => 1], $tx->returnedQuantities());
        $this->assertSame(1, $first->returnedQuantity());
        $this->assertSame($pending->id, $tx->pendingReturn->id);
        $this->assertSame($user->id, $pending->requester->id);
        $this->assertSame(2, $tx->returns()->count());
        $this->assertSame(1, TransactionReturn::decided()->count());
        $this->assertSame(1, TransactionReturn::active()->count());
        $this->assertSame(2, TransactionReturn::ofKind('retur')->count());
        $this->assertSame(0, TransactionReturn::ofKind('batal')->count());
        $this->assertSame($first->id, $pending->items()->first()->transactionItem->id);
        $this->assertSame($first->product_id, $pending->items()->first()->product->id);

        $pending->update(['status' => 'disetujui', 'decided_at' => now(), 'decided_by' => $user->id]);
        $this->assertSame([$first->id => 1], $tx->returnedQuantities());
        $this->assertSame($user->id, $pending->fresh()->decider->id);
        $this->assertNull($tx->fresh()->pendingReturn);
    }

    public function test_voided_transaction_helpers(): void
    {
        $tx = Transaction::factory()->withItems()->create();
        $user = User::factory()->create();
        $tx->update(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => 'Ganda']);
        $this->assertTrue($tx->fresh()->isVoided());
        $this->assertSame($user->id, $tx->fresh()->voider->id);
        $this->assertSame(0, Transaction::notVoided()->count());
    }
}
