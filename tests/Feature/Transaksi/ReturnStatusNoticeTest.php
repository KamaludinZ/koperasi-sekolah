<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Notifikasi\Bell;
use App\Livewire\Transaksi\Returns;
use App\Livewire\Transaksi\ReturnStatusNotice;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TransactionReturnService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnStatusNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->admin = auth()->user();
        $this->cashier = User::where('email', 'kasir@koperasi.test')->first();
    }

    private function request(int $qty = 10): TransactionReturn
    {
        $tx = Transaction::factory()->withItems([$this->pid('ATK001') => $qty])->create(['user_id' => $this->cashier->id]);

        return app(TransactionReturnService::class)->requestVoid($tx, 'batal_beli', null, $this->cashier);
    }

    public function test_pending_request_notifies_approvers_and_decision_notifies_cashier(): void
    {
        $r = $this->request();
        $pending = Notification::where('dedupe_key', "return-pending-{$r->id}")->sole();
        $this->assertSame(['pengurus', null], [$pending->target, $pending->user_id]);
        $this->assertStringContainsString('menunggu persetujuan', $pending->title);
        $this->assertContains($pending->id, array_column(app(NotificationService::class)->list($this->admin), 'id'));
        $this->assertNotContains($pending->id, array_column(app(NotificationService::class)->list($this->cashier), 'id')); // kasir tidak melihat antrean

        app(TransactionReturnService::class)->reject($r, $this->admin, 'Barang sudah dibawa');
        $this->assertFalse(Notification::where('dedupe_key', "return-pending-{$r->id}")->exists());
        $mine = Notification::where('user_id', $this->cashier->id)->sole();
        $this->assertStringContainsString('ditolak', $mine->title);
        $this->assertStringContainsString('Barang sudah dibawa', $mine->message);

        // Notifikasi pribadi hanya untuk kasir itu — Admin (yang melihat semua notifikasi umum) dan kasir lain tidak.
        $this->assertContains($mine->id, array_column(app(NotificationService::class)->list($this->cashier), 'id'));
        $this->assertNotContains($mine->id, array_column(app(NotificationService::class)->list($this->admin), 'id'));
        $other = User::factory()->create(['role_id' => $this->cashier->role_id]);
        $this->assertNotContains($mine->id, array_column(app(NotificationService::class)->list($other), 'id'));
        $this->assertNull(app(NotificationService::class)->find($this->admin, $mine->id));

        $this->actingAs($this->cashier);
        Livewire::test(Bell::class)->assertSee('ditolak');
    }

    public function test_approval_message_and_auto_void_sends_nothing(): void
    {
        $r = $this->request();
        app(TransactionReturnService::class)->approve($r, $this->admin);
        $msg = Notification::where('user_id', $this->cashier->id)->sole();
        $this->assertStringContainsString('disetujui', $msg->title);
        $this->assertStringContainsString('kembalikan Rp', $msg->message);

        Notification::query()->delete();
        $auto = $this->request(1); // kecil & kasir sendiri → otomatis
        $this->assertSame('disetujui', $auto->status);
        $this->assertSame(0, Notification::where('dedupe_key', 'like', 'return-%')->count());
    }

    public function test_notice_banner_shows_decisions_until_seen(): void
    {
        $r = $this->request();
        app(TransactionReturnService::class)->reject($r, $this->admin, 'Sudah lewat jam istirahat');
        $this->request(); // masih menunggu

        $this->actingAs($this->cashier);
        $c = Livewire::test(ReturnStatusNotice::class)->assertSee('1 pengajuan retur/pembatalan Anda sudah diputus')
            ->assertSee($r->number)->assertSee('Alasan ditolak: Sudah lewat jam istirahat');
        $c->call('markSeen')->assertDontSee('sudah diputus')->assertSee('1 pengajuan retur/pembatalan Anda masih menunggu persetujuan.');
        Livewire::test(ReturnStatusNotice::class, ['compact' => true])->assertDontSee('masih menunggu');
        $this->get('/kasir')->assertOk();
    }

    public function test_mine_filter_on_returns_page(): void
    {
        TransactionReturn::factory()->create(['requested_by' => $this->admin->id]);
        TransactionReturn::factory()->create(['requested_by' => $this->cashier->id]);
        $c = Livewire::test(Returns::class)->set('mine', true);
        $this->assertSame([$this->admin->id], array_values(array_unique(array_column($c->instance()->rows, 'requested_by_id'))));
        $this->assertSame(1, $c->instance()->counts['semua']);
    }
}
