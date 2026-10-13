<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Audit\Changes;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditChangeQuery;
use App\Services\TransactionReturnService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnAuditTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $tx;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->cashier = User::where('email', 'kasir@koperasi.test')->first();
        $this->tx = Transaction::factory()->withItems([$this->pid('ATK001') => 2])->create(['user_id' => $this->cashier->id]);
    }

    private function svc(): TransactionReturnService
    {
        return app(TransactionReturnService::class);
    }

    public function test_request_and_approval_are_audited_with_status_change(): void
    {
        $r = $this->svc()->requestReturn($this->tx, [$this->tx->items->first()->id => 1], 'lainnya', true, 'tunai', 'Salah warna', $this->cashier);
        $req = ActivityLog::where('event', 'return.requested')->sole();
        $this->assertSame(['transaction_return', $r->id, 'dibuat', 'transaksi'], [$req->subject_type, $req->subject_id, $req->action, $req->category]);
        $this->assertStringContainsString($r->number, $req->subject_label);
        $this->assertStringContainsString($this->tx->invoice_number, $req->subject_label);
        $this->assertSame('Lainnya: Salah warna', $req->reason);
        $this->assertSame('menunggu', $req->new_values['status']);
        $this->assertSame($this->tx->id, $req->properties['transaction_id']);

        $this->svc()->approve($r, auth()->user(), 'Sesuai SOP');
        $ok = ActivityLog::where('event', 'return.approved')->sole();
        $this->assertSame(['diubah', ['status' => 'menunggu'], 'disetujui', 'Admin Koperasi', 'Sesuai SOP', (int) $r->amount],
            [$ok->action, $ok->old_values, $ok->new_values['status'], $ok->new_values['decided_by'], $ok->reason, (int) $ok->amount]);
        $this->assertSame('Admin Koperasi', $ok->actor_name);
    }

    public function test_void_rejection_and_auto_approval_audited(): void
    {
        $big = Transaction::factory()->withItems([$this->pid('ATK001') => 10])->create(['user_id' => $this->cashier->id]);
        $v = $this->svc()->requestVoid($big, 'batal_beli', null, $this->cashier);
        $this->svc()->reject($v, auth()->user(), 'Barang sudah dibawa pulang');
        $rej = ActivityLog::where('event', 'void.rejected')->sole();
        $this->assertSame(['ditolak', 'Barang sudah dibawa pulang'], [$rej->new_values['status'], $rej->reason]);

        $this->actingAs($this->cashier);
        $small = Transaction::factory()->withItems([$this->pid('ATK001') => 1])->create(['user_id' => $this->cashier->id]);
        $this->svc()->requestVoid($small, 'salah_input', null, $this->cashier);
        $auto = ActivityLog::where('event', 'void.approved')->sole();
        $this->assertTrue($auto->properties['auto']);
        $this->assertTrue($auto->properties['transaction_voided']);
        $this->assertSame('Otomatis', $auto->new_values['decided_by']);
        $this->assertStringContainsString('disetujui otomatis', $auto->description);
    }

    public function test_changes_page_lists_returns_and_object_history(): void
    {
        $r = $this->svc()->requestReturn($this->tx, [$this->tx->items->first()->id => 1], 'rusak', false, 'tunai', null, $this->cashier);
        $this->svc()->approve($r, auth()->user());

        $this->assertSame(2, app(AuditChangeQuery::class)->query(['entity' => 'retur'])->count());
        $this->assertSame(2, app(AuditChangeQuery::class)->summary(now()->subDay(), now()->addDay())['by_entity']['retur']);
        Livewire::test(Changes::class)->set('entity', 'retur')->assertSee($r->number)->assertSee('Retur & pembatalan');
        $this->get(route('audit.perubahan', ['jenis' => 'retur']))->assertOk()->assertSee($r->number);
    }
}
