<?php

namespace Tests\Feature\Sistem;

use App\Livewire\Sistem\Health;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HealthPageTest extends TestCase
{
    use RefreshDatabase;

    private function healthy(): void
    {
        \Illuminate\Support\Facades\Cache::put('health:scheduler_heartbeat', now()->toDateTimeString());
        \App\Models\Backup::factory()->create(['started_at' => now()->subHours(3)]);
    }

    public function test_admin_sees_menu_and_page(): void
    {
        $this->healthy();
        $this->get('/kasir')->assertSee(route('sistem'));
        $this->get('/sistem')->assertOk()->assertSee('Semua sistem berjalan normal')
            ->assertSee('Basis data')->assertSee('Cache')->assertSee('Penyimpanan')->assertSee('Antrean latar')
            ->assertSee('Penjadwal tugas')->assertSee('Cadangan data')->assertSee('Gangguan terbaru')->assertSee('Periksa ulang');
    }

    public function test_other_roles_cannot_open(): void
    {
        foreach (['kasir@koperasi.test', 'pengurus@koperasi.test'] as $email) {
            $this->loginAs($email);
            $this->get('/sistem')->assertForbidden();
        }
    }

    public function test_report_shape_and_overall_status(): void
    {
        $this->healthy();
        $r = SystemHealth::report();
        $this->assertSame(['database', 'cache', 'storage', 'queue', 'scheduler', 'backup', 'app'], array_column($r['checks'], 'key'));
        $this->assertSame('ok', collect($r['checks'])->firstWhere('key', 'database')['status']);
        $this->assertArrayHasKey('transactions_today', $r['metrics']);
        $this->assertContains($r['status'], array_keys(SystemHealth::STATUSES));

        // Tugas gagal dalam 24 jam → antrean & status keseluruhan "peringatan".
        DB::table('failed_jobs')->insert(['uuid' => 'x-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        $r = SystemHealth::report();
        $this->assertSame('peringatan', collect($r['checks'])->firstWhere('key', 'queue')['status']);
        $this->assertSame('peringatan', $r['status']);
        Livewire::test(Health::class)->assertSee('Ada yang perlu diperhatikan')->assertSee('1 tugas gagal dalam 24 jam.')
            ->call('recheck')->assertDispatched('toast');
    }

    public function test_bytes_formatting(): void
    {
        $this->assertSame(['512 B', '2 KB', '2,4 MB', '1,5 GB'], [SystemHealth::bytes(512), SystemHealth::bytes(2048), SystemHealth::bytes(2_516_582), SystemHealth::bytes(1_610_612_736)]);
    }

    public function test_scheduler_worker_backup_and_incidents_are_real(): void
    {
        $check = fn (string $key) => collect(SystemHealth::report()['checks'])->firstWhere('key', $key);

        $this->assertSame('peringatan', $check('scheduler')['status']); // belum ada denyut
        \Illuminate\Support\Facades\Cache::put('health:scheduler_heartbeat', now()->subMinutes(20)->toDateTimeString());
        $this->assertSame('gangguan', $check('scheduler')['status']);

        $this->assertSame('gangguan', $check('backup')['status']); // belum ada cadangan
        \App\Models\Backup::factory()->failed('Disk penuh')->create(['started_at' => now()->subHours(2)]);
        $incidents = SystemHealth::report()['incidents'];
        $this->assertSame('Cadangan gagal: Disk penuh', $incidents[0]['message']);

        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'ekspor', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        $this->assertSame('gangguan', $check('queue')['status']); // ada tugas, pekerja tidak terlihat
        \Illuminate\Support\Facades\Cache::put('health:worker_heartbeat', now()->toDateTimeString());
        $this->assertSame('ok', $check('queue')['status']);
    }

    public function test_json_endpoint_and_up(): void
    {
        $this->getJson(route('sistem.kesehatan.data'))->assertOk()->assertJsonStructure(['status', 'checked_at', 'checks', 'metrics', 'incidents']);
        $this->get('/up')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->getJson(route('sistem.kesehatan.data'))->assertForbidden();
    }
}
