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

    public function test_admin_sees_menu_and_page(): void
    {
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
}
