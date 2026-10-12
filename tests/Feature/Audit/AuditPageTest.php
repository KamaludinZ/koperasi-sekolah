<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Index;
use App\Models\ActivityLog;
use App\Services\AuditLog;
use Database\Seeders\ActivityLogSeeder;
use Database\Seeders\MemberSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 15:00:00');
    }

    private function seedLogs(): void
    {
        AuditLog::record('auth.login', 'auth', 'Bu Rina masuk sebagai Kasir', actor: ['id' => 2, 'name' => 'Bu Rina', 'role' => 'kasir']);
        AuditLog::record('auth.failed', 'auth', 'Gagal masuk (x@y.test)', actor: ['name' => 'x@y.test']);
        AuditLog::record('sale.created', 'transaksi', 'Penjualan INV-20261014-0001', ['type' => 'transaction', 'id' => 1, 'label' => 'INV-20261014-0001'], [], 12000, ['id' => 2, 'name' => 'Bu Rina', 'role' => 'kasir']);
        AuditLog::record('savings.withdraw', 'tabungan', 'Penarikan tabungan Andi', null, [], 3000, ['id' => 2, 'name' => 'Bu Rina', 'role' => 'kasir']);
        AuditLog::record('member.updated', 'anggota', 'Data anggota Andi diubah: class', null, ['changes' => ['class' => ['from' => '7A', 'to' => '8A']]]);
    }

    public function test_admin_and_pengurus_can_open_but_kasir_cannot(): void
    {
        $this->get('/audit')->assertOk()->assertSee('Audit & Riwayat');
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/audit')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/audit')->assertForbidden();
    }

    public function test_summary_views_and_filters(): void
    {
        $this->seedLogs();
        $c = Livewire::test(Index::class)->call('setPeriod', 'hari-ini');
        $s = $c->instance()->summary;

        $this->assertSame(5, $s['total']);
        $this->assertSame(1, $s['logins']);
        $this->assertSame(1, $s['failed_logins']);
        $this->assertSame(12000, $s['sales_amount']);
        $this->assertSame(3000, $s['withdrawals']);

        $this->assertSame(2, $c->call('setView', 'login')->instance()->logs->total());
        $this->assertSame(2, $c->call('setView', 'sirkulasi')->instance()->logs->total());
        $this->assertSame(1, $c->call('setView', 'data')->instance()->logs->total());
        $c->call('setView', 'semua')->set('search', 'inv-20261014')->assertSee('Penjualan INV-20261014-0001');
        $this->assertSame(1, $c->instance()->logs->total());

        $c->set('search', '')->call('filterActor', 'Bu Rina');
        $this->assertSame(3, $c->instance()->logs->total());

        $rina = collect($c->instance()->actors)->firstWhere('name', 'Bu Rina');
        $this->assertSame(['actions' => 3, 'sales' => 1, 'sales_amount' => 12000], collect($rina)->only('actions', 'sales', 'sales_amount')->all());
    }

    public function test_detail_panel_shows_changes(): void
    {
        $this->seedLogs();
        $id = ActivityLog::where('event', 'member.updated')->value('id');

        Livewire::test(Index::class)->call('show', $id)
            ->assertSee('Perubahan')->assertSee('Kelas')->assertSee('7A')->assertSee('8A')->assertSee('Admin Koperasi');
    }

    public function test_export_csv_is_logged(): void
    {
        $this->seedLogs();
        Livewire::test(Index::class)->call('export')->assertFileDownloaded();

        $this->assertSame('Admin Koperasi', ActivityLog::where('event', 'audit.exported')->value('actor_name'));
    }

    public function test_demo_seeder_backfills_history(): void
    {
        $this->seed([MemberSeeder::class, TransactionSeeder::class, ActivityLogSeeder::class]);

        $this->assertSame(\App\Models\Transaction::count(), ActivityLog::where('event', 'sale.created')->count());
        $this->assertGreaterThan(0, ActivityLog::where('event', 'auth.login')->count());
        $this->assertGreaterThan(0, ActivityLog::where('event', 'savings.deposit')->count());
        $this->get('/audit?periode=semua')->assertOk()->assertSee('Aktivitas per petugas');
    }
}
