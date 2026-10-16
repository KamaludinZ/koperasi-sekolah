<?php

namespace Tests\Feature\Keamanan;

use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Eskalasi hak lewat kelola pengguna: area Pengguna khusus peran Admin di tingkat rute (walau menunya diberikan ke
 * peran lain), dan sebagai pertahanan berlapis UserService menolak pelaku non-Admin memberi peran Admin, mengubah
 * akun Admin, membuat akun Admin, atau mengubah izin menu peran.
 */
class UserAdminEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $pengurus;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $role = Role::named('pengurus');
        $role->update(['permissions' => [...$role->permissions, 'pengguna']]);              // menu Pengguna diberikan ke Pengurus
        $this->pengurus = User::where('email', 'pengurus@koperasi.test')->first();
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
    }

    public function test_user_management_routes_stay_admin_only(): void
    {
        $this->actingAs($this->pengurus->fresh(), 'web');
        $this->get('/pengguna')->assertForbidden();
        $this->putJson("/pengguna/{$this->pengurus->id}/peran", ['roles' => ['pengurus', 'admin']])->assertForbidden();
        $this->putJson('/pengguna/peran/pengurus', ['permissions' => ['sistem']])->assertForbidden();
        $this->assertFalse($this->pengurus->fresh()->hasRole(Role::ADMIN));
    }

    public function test_service_rejects_non_admin_actor_escalation(): void
    {
        $svc = app(UserService::class);
        $attempts = [
            fn () => $svc->updateRoles($this->pengurus, ['pengurus', 'admin'], $this->pengurus),
            fn () => $svc->updateRoles($this->kasir, ['kasir', 'admin'], $this->pengurus),
            fn () => $svc->toggleActive($this->admin, $this->pengurus),
            fn () => $svc->create(['name' => 'Penyusup', 'email' => 'penyusup@koperasi.test', 'password' => 'RahasiaKuat123!', 'roles' => ['admin']], $this->pengurus),
            fn () => $svc->updatePermissions(Role::named('pengurus'), array_keys(Menu::ITEMS), null, $this->pengurus),
        ];
        foreach ($attempts as $i => $try) {
            try {
                $try();
                $this->fail("percobaan #{$i} seharusnya ditolak");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertFalse($this->pengurus->fresh()->hasRole(Role::ADMIN));
        $this->assertFalse($this->kasir->fresh()->hasRole(Role::ADMIN));
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertNull(User::where('email', 'penyusup@koperasi.test')->first());
        $this->assertNotContains('sistem', Role::named('pengurus')->permissions);
    }

    public function test_non_admin_actor_can_still_manage_non_admin_accounts_and_admin_unaffected(): void
    {
        app(UserService::class)->toggleActive($this->kasir, $this->pengurus);
        $this->assertFalse($this->kasir->fresh()->is_active);
        app(UserService::class)->updateRoles($this->kasir->fresh(), ['kasir', 'admin'], $this->admin);
        $this->assertTrue($this->kasir->fresh()->hasRole(Role::ADMIN));
        $this->actingAs($this->admin, 'web');
        $this->putJson('/pengguna/peran/pengurus', ['permissions' => Role::named('pengurus')->permissions])->assertOk();
    }
}
