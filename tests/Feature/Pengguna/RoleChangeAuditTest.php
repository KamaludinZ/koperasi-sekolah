<?php

namespace Tests\Feature\Pengguna;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditIntegrity;
use App\Services\AuditLog;
use App\Services\UserService;
use App\Support\AuditGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleChangeAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_change_records_actor_target_time_added_removed_once(): void
    {
        $this->travelTo('2026-10-15 09:30:00');
        $admin = $this->loginAs('admin');
        $u = User::factory()->role('kasir')->create(['name' => 'Bu Siti', 'email' => 'siti@koperasi.test']);
        $maxId = (int) ActivityLog::max('id');
        app(UserService::class)->updateRoles($u, ['pengurus', 'admin'], $admin);

        $logs = ActivityLog::where('id', '>', $maxId)->get();
        $this->assertSame(['user.role_changed'], $logs->pluck('event')->unique()->values()->all()); // tanpa catatan per baris pivot
        $log = $logs->sole();
        $this->assertSame([$admin->id, $admin->name, 'admin'], [$log->actor_id, $log->actor_name, $log->actor_role]);
        $this->assertSame(['user', $u->id, 'Bu Siti · siti@koperasi.test'], [$log->subject_type, (int) $log->subject_id, $log->subject_label]);
        $this->assertSame('2026-10-15 09:30:00', $log->created_at->toDateTimeString());
        $this->assertSame([['admin', 'pengurus'], ['kasir']], [$log->properties['added'], $log->properties['removed']]);
        $this->assertSame(['roles' => 'kasir'], $log->old_values);
        $this->assertSame(['roles' => 'admin, pengurus'], $log->new_values);
        $this->assertTrue(AuditIntegrity::isValid($log));
    }

    public function test_pivot_change_outside_official_flow_is_still_logged(): void
    {
        $admin = $this->loginAs('admin');
        $u = User::factory()->role('kasir')->create(['name' => 'Pak Lain']);
        $pengurus = Role::named('pengurus');

        $u->roles()->attach($pengurus->id, ['created_at' => now()]);
        $added = ActivityLog::where('event', 'user.role_added')->sole();
        $this->assertSame([$admin->name, (int) $added->subject_id, ['pengurus'], ['role' => 'pengurus']],
            [$added->actor_name, $u->id, $added->properties['added'], $added->new_values]);
        $this->assertTrue($added->properties['otomatis']);

        $u->roles()->detach($pengurus->id);
        $removed = ActivityLog::where('event', 'user.role_removed')->sole();
        $this->assertSame([['pengurus'], ['role' => 'pengurus']], [$removed->properties['removed'], $removed->old_values]);

        $u->roles()->sync([Role::named('admin')->id => ['created_at' => now()], Role::named('kasir')->id => ['created_at' => now()]]);
        $this->assertSame(2, ActivityLog::where('event', 'user.role_added')->count());

        AuditLog::quietly(fn () => $u->roles()->detach(Role::named('admin')->id)); // seeder/pemeliharaan diam
        $this->assertSame(1, ActivityLog::where('event', 'user.role_removed')->count());
    }

    public function test_role_change_logs_are_tamper_evident(): void
    {
        $admin = $this->loginAs('admin');
        $u = User::factory()->role('kasir')->create();
        app(UserService::class)->addRole($u, 'pengurus', $admin);
        $log = ActivityLog::where('event', 'user.role_changed')->sole();
        $this->artisan('audit:verifikasi')->assertSuccessful();

        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $log->id)->update(['properties' => json_encode(['added' => ['admin'], 'removed' => []])]));
        $this->assertFalse(AuditIntegrity::isValid($log->fresh()));
        $this->artisan('audit:verifikasi')->assertFailed();
    }

    public function test_permission_changes_of_a_role_are_logged(): void
    {
        $admin = $this->loginAs('admin');
        app(UserService::class)->updatePermissions(Role::named('kasir'), ['kasir', 'transaksi']);
        $log = ActivityLog::where('event', 'role.permissions_updated')->sole();
        $this->assertSame([$admin->name, 'role'], [$log->actor_name, $log->subject_type]);
        $this->assertTrue(AuditIntegrity::isValid($log));
    }
}
