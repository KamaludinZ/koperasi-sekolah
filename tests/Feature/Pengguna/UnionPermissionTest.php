<?php

namespace Tests\Feature\Pengguna;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Support\Petugas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UnionPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string ...$roles): User
    {
        $this->seed(\Database\Seeders\UserSeeder::class);
        $u = User::factory()->role($roles[0])->create();
        foreach (array_slice($roles, 1) as $r) {
            $u->roles()->attach(Role::named($r)->id, ['created_at' => now()]);
        }

        return $u->fresh(['role', 'roles']);
    }

    public function test_permissions_are_the_union_of_all_roles_without_duplicates(): void
    {
        $kasir = Role::named('kasir');
        $pengurus = Role::named('pengurus');
        $u = $this->user('kasir', 'pengurus');

        $expected = collect([...$kasir->permissions, ...$pengurus->permissions])->unique()->sort()->values()->all();
        $actual = collect($u->permissions())->sort()->values()->all();
        $this->assertSame($expected, $actual);
        $this->assertSame(count(array_unique($u->permissions())), count($u->permissions()));
        $this->assertSame(array_slice($u->permissions(), 0, count($kasir->permissions)), $kasir->permissions); // peran utama (kasir) dulu

        $this->assertTrue($u->canOpen('kasir'));      // dari kasir
        $this->assertTrue($u->canOpen('pinjaman'));   // dari pengurus (kasir sendiri tidak boleh)
        $this->assertFalse($u->canOpen('pengguna'));  // tidak dari peran mana pun
        $this->assertTrue($u->hasRole('pengurus'));
        $this->assertTrue($u->hasRole('admin', 'kasir'));
        $this->assertFalse($u->hasRole('admin'));
    }

    public function test_changing_a_role_permission_updates_everyone_holding_it(): void
    {
        $u = $this->user('kasir', 'pengurus');
        $this->assertTrue($u->canOpen('laporan'));
        app(\App\Services\UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['laporan'])));
        $this->assertFalse($u->fresh(['role', 'roles'])->canOpen('laporan')); // kasir juga tidak punya laporan
        $this->assertTrue($u->fresh(['role', 'roles'])->canOpen('kas'));     // masih dari kasir & pengurus
    }

    public function test_inactive_account_opens_nothing(): void
    {
        $u = $this->user('admin', 'pengurus');
        $u->forceFill(['is_active' => false])->save();
        $this->assertFalse($u->fresh(['role', 'roles'])->canOpen('pengguna'));
    }

    public function test_session_array_gates_routes_and_notifications_use_all_roles(): void
    {
        $u = $this->user('kasir', 'pengurus');
        $this->actingAs($u);
        $p = Petugas::current();
        $this->assertSame(['pengurus', 'kasir'], $p['roles']);
        $this->assertContains('pinjaman', $p['permissions']);

        // gate & rute khusus pengurus terbuka karena salah satu perannya pengurus
        $this->assertTrue(Gate::allows('kelola-pinjaman'));
        $this->get(route('pinjaman'))->assertOk();
        $this->get(route('kasir'))->assertOk();
        $this->get('/pengguna')->assertForbidden(); // peran:admin — tidak dipegang

        Notification::create(['type' => 'pengumuman', 'title' => 'Untuk kasir', 'message' => 'x', 'target' => 'kasir']);
        Notification::create(['type' => 'pengumuman', 'title' => 'Untuk pengurus', 'message' => 'x', 'target' => 'pengurus']);
        Notification::create(['type' => 'pengumuman', 'title' => 'Untuk admin', 'message' => 'x', 'target' => 'admin']);
        $this->assertEqualsCanonicalizing(['Untuk kasir', 'Untuk pengurus'], $u->inbox()->pluck('title')->all());
    }

    public function test_single_role_accounts_behave_as_before(): void
    {
        $k = $this->user('kasir');
        $this->assertSame(Role::named('kasir')->permissions, $k->permissions());
        $this->assertFalse($k->canOpen('pinjaman'));
        $this->actingAs($k);
        $this->get(route('pinjaman'))->assertForbidden();
    }
}
