<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Returns;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $cashier = User::where('email', 'kasir@koperasi.test')->first();
        foreach (range(13, 0) as $h) {
            Transaction::factory()->withItems()->at(now()->subHours($h * 3)->toDateTimeString())->create(['user_id' => $cashier->id]);
        }
    }

    private function pending(\Livewire\Features\SupportTesting\Testable $c): array
    {
        return array_values(array_filter($c->instance()->all, fn ($r) => $r['status'] === 'menunggu'));
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
        $c = Livewire::test(Returns::class)->assertSee('menunggu persetujuan Anda')->call('nextPending');
        $pending = $this->pending($c);
        $oldest = collect($pending)->sortBy('requested_at')->first();
        $c->assertSet('selectedId', $oldest['id'])->assertSee('Keputusan')->assertSee('Setujui');

        $c->call('approve', $oldest['id'])->assertHasNoErrors();
        $r = collect($c->instance()->all)->firstWhere('id', $oldest['id']);
        $this->assertSame('disetujui', $r['status']);
        $this->assertSame('Admin Koperasi', $r['decided_by']);
        $this->assertCount(count($pending) - 1, $this->pending($c));
        $c->assertDispatched('toast')->assertDontSee('Tolak</button>', false);

        $c->call('approve', $oldest['id'])->assertDispatched('toast', type: 'error');
    }

    public function test_reject_requires_reason(): void
    {
        $c = Livewire::test(Returns::class);
        $id = $this->pending($c)[0]['id'];
        $c->call('show', $id)->call('reject', $id)->assertHasErrors(['decisionNote' => 'required']);
        $c->set('decisionNote', 'oke')->call('reject', $id)->assertHasErrors(['decisionNote' => 'min']);
        $c->set('decisionNote', 'Barang sudah dipakai')->call('reject', $id)->assertHasNoErrors()->assertSet('decisionNote', '');
        $r = collect($c->instance()->all)->firstWhere('id', $id);
        $this->assertSame(['ditolak', 'Barang sudah dipakai'], [$r['status'], $r['decision_note']]);
        $c->assertSee('Barang sudah dipakai');
    }

    public function test_cashier_cannot_decide(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $c = Livewire::test(Returns::class)->assertDontSee('menunggu persetujuan Anda');
        $id = $this->pending($c)[0]['id'];
        $c->call('show', $id)->assertSee('Menunggu persetujuan Admin/Pengurus')->assertDontSee('Keputusan');
        $c->call('approve', $id)->assertForbidden();
    }

    public function test_cannot_decide_own_request(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        User::where('email', 'pengurus@koperasi.test')->update(['name' => 'Bu Rina']); // sama dengan nama pengaju (kasir transaksi)
        auth()->user()->refresh();
        $c = Livewire::test(Returns::class);
        $id = $this->pending($c)[0]['id'];
        $c->call('show', $id)->assertSee('Anda buat sendiri');
        $c->call('approve', $id)->assertHasErrors('decisionNote');
        $this->assertSame('menunggu', collect($c->instance()->all)->firstWhere('id', $id)['status']);
    }
}
