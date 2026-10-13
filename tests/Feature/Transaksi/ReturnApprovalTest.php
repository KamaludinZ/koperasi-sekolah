<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Returns;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnApprovalTest extends TestCase
{
    use RefreshDatabase;

    private TransactionReturn $older;

    private TransactionReturn $newer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $cashier = User::where('email', 'kasir@koperasi.test')->first();
        $tx = fn () => Transaction::factory()->withItems([$this->pid('ATK001') => 2])->create(['user_id' => $cashier->id]);
        $this->older = TransactionReturn::factory()->create(['transaction_id' => $tx()->id, 'requested_by' => $cashier->id, 'requested_at' => now()->subHours(2), 'restock' => true]);
        $this->newer = TransactionReturn::factory()->create(['transaction_id' => $tx()->id, 'requested_by' => $cashier->id, 'requested_at' => now()->subHour()]);
    }

    public function test_gate_admin_and_pengurus_only(): void
    {
        $this->assertTrue(Gate::allows('setujui-retur'));
        $this->loginAs('pengurus@koperasi.test');
        $this->assertTrue(Gate::allows('setujui-retur'));
        $this->loginAs('kasir@koperasi.test');
        $this->assertFalse(Gate::allows('setujui-retur'));
    }

    public function test_admin_approves_pending_request(): void
    {
        $stock = Product::find($this->pid('ATK001'))->stock;
        $c = Livewire::test(Returns::class)->assertSee('menunggu persetujuan Anda')->call('nextPending')
            ->assertSet('selectedId', $this->older->id)->assertSee('Keputusan')->assertSee('Setujui');

        $c->call('approve', $this->older->id)->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $this->assertSame(['disetujui', auth()->id()], [$this->older->fresh()->status, $this->older->fresh()->decided_by]);
        $this->assertSame($stock + 1, Product::find($this->pid('ATK001'))->stock);
        $this->assertSame(1, $c->instance()->counts['menunggu']);

        $c->call('approve', $this->older->id)->assertDispatched('toast', type: 'error');
    }

    public function test_reject_requires_reason(): void
    {
        $id = $this->newer->id;
        $c = Livewire::test(Returns::class)->call('show', $id);
        $c->call('reject', $id)->assertHasErrors(['decisionNote' => 'required']);
        $c->set('decisionNote', 'oke')->call('reject', $id)->assertHasErrors(['decisionNote' => 'min']);
        $c->set('decisionNote', 'Barang sudah dipakai')->call('reject', $id)->assertHasNoErrors()->assertSet('decisionNote', '');
        $this->assertSame(['ditolak', 'Barang sudah dipakai'], [$this->newer->fresh()->status, $this->newer->fresh()->decision_note]);
        $c->assertSee('Barang sudah dipakai');
    }

    public function test_cashier_cannot_decide(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $c = Livewire::test(Returns::class)->assertDontSee('menunggu persetujuan Anda');
        $c->call('show', $this->older->id)->assertSee('Menunggu persetujuan Admin/Pengurus')->assertDontSee('Keputusan');
        $c->call('approve', $this->older->id)->assertForbidden();
    }

    public function test_cannot_decide_own_request(): void
    {
        $this->older->update(['requested_by' => auth()->id()]);
        $c = Livewire::test(Returns::class)->call('show', $this->older->id)->assertSee('Anda buat sendiri');
        $c->call('approve', $this->older->id)->assertHasErrors('decisionNote');
        $this->assertSame('menunggu', $this->older->fresh()->status);
    }
}
