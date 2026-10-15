<?php

namespace Tests\Feature\Pengguna;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditIntegrity;
use App\Services\RoleAuditService;
use App\Services\UserService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    private function lastRoleLog(): ActivityLog
    {
        return ActivityLog::where('event', 'user.role_changed')->latest('id')->firstOrFail();
    }

    public function test_diff(): void
    {
        $d = app(RoleAuditService::class)->diff(['kasir', 'pengurus'], ['admin', 'kasir', 'kasir']);
        $this->assertSame(['added' => ['admin'], 'removed' => ['pengurus'], 'kept' => ['kasir']], $d);
    }

    public function test_save_roles_records_full_diff_with_reason_primary_and_menu_impact(): void
    {
        $u = User::factory()->role('kasir')->create();
        $this->putJson(route('pengguna.peran.save', $u), ['roles' => ['pengurus', 'kasir'], 'alasan' => 'merangkap bendahara'])->assertOk();
        $log = $this->lastRoleLog();
        $p = $log->properties;

        $this->assertSame([['kasir'], ['pengurus', 'kasir'], ['pengurus'], []], [$p['before'], $p['after'], $p['added'], $p['removed']]);
        $this->assertSame(['kasir', 'pengurus'], [$p['primary_from'], $p['primary_to']]); // peran utama ikut prioritas
        $this->assertContains('pinjaman', $p['menus_gained']);
        $this->assertSame([], $p['menus_lost']);
        $this->assertSame('peran', $p['source']);
        $this->assertSame('merangkap bendahara', $log->reason);
        $this->assertSame(['roles' => 'kasir'], $log->old_values);
        $this->assertSame(['roles' => 'pengurus, kasir'], $log->new_values);
        $this->assertTrue(AuditIntegrity::isValid($log));
    }

    public function test_single_add_and_remove_are_tagged_with_their_source_and_reason(): void
    {
        $u = User::factory()->role('kasir')->create();
        $this->postJson(route('pengguna.peran.add', [$u, 'pengurus']), ['alasan' => 'tugas tambahan'])->assertOk();
        $this->assertSame(['tambah', 'tugas tambahan'], [$this->lastRoleLog()->properties['source'], $this->lastRoleLog()->reason]);

        $this->deleteJson(route('pengguna.peran.remove', [$u, 'pengurus']))->assertOk();
        $log = $this->lastRoleLog();
        $this->assertSame(['cabut', ['pengurus']], [$log->properties['source'], $log->properties['removed']]);
        $this->assertContains('pinjaman', $log->properties['menus_lost']);
        $this->assertNull($log->reason);

        $this->postJson(route('pengguna.peran.add', [$u, 'pengurus']), ['alasan' => str_repeat('x', 201)])->assertUnprocessable();
    }

    public function test_form_edit_combines_other_changes_with_role_diff(): void
    {
        $u = User::factory()->role('kasir')->create(['name' => 'Lama']);
        app(UserService::class)->update($u, ['name' => 'Baru', 'email' => $u->email, 'roles' => ['kasir', 'pengurus']], $this->admin);
        $log = $this->lastRoleLog();
        $this->assertSame('formulir', $log->properties['source']);
        $this->assertSame(['from' => 'Lama', 'to' => 'Baru'], $log->properties['changes']['name']);
        $this->assertSame(['pengurus'], $log->properties['added']);

        // tanpa perubahan peran → user.updated tanpa diff peran
        app(UserService::class)->update($u->fresh(), ['name' => 'Baru Lagi', 'email' => $u->email, 'roles' => ['kasir', 'pengurus']], $this->admin);
        $upd = ActivityLog::where('event', 'user.updated')->sole();
        $this->assertArrayNotHasKey('added', $upd->properties);
    }

    public function test_new_account_logs_initial_roles_as_diff_from_nothing(): void
    {
        app(UserService::class)->create(['name' => 'Baru', 'email' => 'baru@koperasi.test', 'password' => 'rahasia-panjang-1', 'roles' => ['kasir', 'pengurus']]);
        $p = ActivityLog::where('event', 'user.created')->latest('id')->first()->properties;
        $this->assertSame([[], ['pengurus', 'kasir'], 'akun_baru'], [$p['before'], $p['added'], $p['source']]);
        $this->assertSame(['pengurus', 'kasir'], $p['roles']); // format lama tetap ada
    }

    public function test_no_change_is_not_logged(): void
    {
        $u = User::factory()->role('kasir')->create();
        app(UserService::class)->updateRoles($u, ['kasir'], $this->admin, 'tidak ada beda');
        $this->assertSame(0, ActivityLog::where('event', 'user.role_changed')->count());
        $this->assertNull(app(RoleAuditService::class)->recordUserRoles($u, ['kasir'], ['kasir']));
    }

    public function test_role_permission_change_records_holders_and_reason(): void
    {
        $this->putJson(route('pengguna.roles.update', 'kasir'), ['permissions' => [...Role::named('kasir')->permissions, 'laporan'], 'alasan' => 'kasir menutup buku harian'])->assertOk();
        $log = ActivityLog::where('event', 'role.permissions_updated')->sole();
        $this->assertSame(['laporan'], $log->properties['added']);
        $this->assertSame(User::holdingRole('kasir')->count(), $log->properties['holders_count']);
        $this->assertSame('kasir menutup buku harian', $log->reason);
        $this->assertTrue(AuditIntegrity::isValid($log));
    }
}
