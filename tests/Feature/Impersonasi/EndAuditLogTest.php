<?php

namespace Tests\Feature\Impersonasi;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use App\Services\AuditIntegrity;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    private function start(int $minutes = 10): Impersonation
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => $minutes]);

        return Impersonation::latest('started_at')->first();
    }

    private function endLog(): ActivityLog
    {
        return ActivityLog::where('event', 'impersonation.ended')->sole();
    }

    public function test_manual_return_is_logged_with_admin_actor_and_duration(): void
    {
        $rec = $this->start();
        $this->travel(3)->minutes();
        $this->post('/impersonasi/selesai');

        $log = $this->endLog();
        $this->assertSame('auth', $log->category);
        $this->assertSame([$this->admin->id, 'admin'], [$log->actor_id, $log->actor_role]); // admin, bukan kasir
        $this->assertSame(['user', $this->kasir->id], [$log->subject_type, (int) $log->subject_id]);
        $this->assertSame(['manual', 3, $rec->id], [$log->properties['end_reason'], $log->properties['duration_minutes'], $log->properties['impersonation_id']]);
        $this->assertStringContainsString('selesai sebagai '.$this->kasir->name.' — kembali lewat tombol (3 menit)', $log->description);
        $this->assertSame('membantu kasir memeriksa struk', $log->reason);
        $this->assertTrue(AuditIntegrity::isValid($log));
    }

    public function test_expiry_detected_by_middleware_is_logged_with_planned_end_time(): void
    {
        $rec = $this->start(10);
        $this->travel(25)->minutes();
        $this->get('/kasir');
        $log = $this->endLog();
        $this->assertSame('kedaluwarsa', $log->properties['end_reason']);
        $this->assertSame(10, $log->properties['duration_minutes']); // berakhir = batas waktunya, bukan saat terdeteksi
        $this->assertSame($rec->fresh()->expires_at->toIso8601String(), $log->properties['ended_at']);
    }

    public function test_revoked_and_logout_endings_are_logged(): void
    {
        $this->start();
        $this->kasir->forceFill(['is_active' => false])->save();
        $this->get('/kasir');
        $this->assertSame('dicabut', $this->endLog()->properties['end_reason']);
    }

    public function test_abandoned_sessions_are_closed_by_schedule_and_logged_once(): void
    {
        $rec = $this->start(10);
        $this->travel(40)->minutes();
        $this->artisan('impersonasi:tutup-kedaluwarsa')->expectsOutputToContain('1 sesi impersonasi kedaluwarsa ditutup.')->assertSuccessful();
        $this->artisan('impersonasi:tutup-kedaluwarsa')->expectsOutputToContain('0 sesi')->assertSuccessful();
        $this->assertSame('kedaluwarsa', $rec->fresh()->end_reason);
        $this->assertSame(1, ActivityLog::where('event', 'impersonation.ended')->count());

        // admin kembali ke tab lama: middleware membersihkan tanpa mencatat dua kali
        $this->get('/kasir');
        $this->assertSame(1, ActivityLog::where('event', 'impersonation.ended')->count());
    }

    public function test_active_sessions_are_not_touched_by_schedule(): void
    {
        $rec = $this->start(30);
        $this->travel(5)->minutes();
        $this->artisan('impersonasi:tutup-kedaluwarsa')->assertSuccessful();
        $this->assertNull($rec->fresh()->ended_at);
        $this->assertSame(0, ActivityLog::where('event', 'impersonation.ended')->count());
    }

    public function test_starting_again_closes_and_logs_stale_session(): void
    {
        $old = Impersonation::create(['admin_id' => $this->admin->id, 'admin_name' => $this->admin->name, 'target_type' => 'petugas',
            'target_user_id' => $this->kasir->id, 'target_name' => $this->kasir->name, 'reason' => 'sesi lama di perangkat lain',
            'started_at' => now()->subMinutes(5), 'expires_at' => now()->addMinutes(25)]);
        $this->start();
        $log = $this->endLog();
        $this->assertSame([$old->id, 'sesi_berakhir'], [$log->properties['impersonation_id'], $log->properties['end_reason']]);
    }

    public function test_member_session_end_logged_and_deleted_admin_keeps_name(): void
    {
        $m = Member::factory()->create(['name' => 'Siti']);
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $tmpAdmin = User::factory()->admin()->create(['name' => 'Admin Sementara']);
        $this->actingAs($tmpAdmin);
        $this->post(route('pengguna.impersonasi.anggota', $m), ['reason' => 'membantu anggota memakai portal', 'minutes' => 10]);
        $rec = Impersonation::sole();

        $tmpAdmin->roles()->detach();
        $tmpAdmin->delete();
        $this->travel(11)->minutes();
        $this->artisan('impersonasi:tutup-kedaluwarsa')->assertSuccessful();
        $log = $this->endLog();
        $this->assertSame(['member', $m->id, 'Admin Sementara', null], [$log->subject_type, (int) $log->subject_id, $log->actor_name, $log->actor_id]);
        $this->assertSame('kedaluwarsa', $rec->fresh()->end_reason);
    }

    public function test_command_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);
        $this->assertTrue($events->contains(fn ($c) => str_contains((string) $c, 'impersonasi:tutup-kedaluwarsa')));
    }
}
