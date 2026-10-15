<?php

namespace Tests\Feature\Pengguna;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\UserService;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleChangeRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->role($roles[0])->create();
        foreach (array_slice($roles, 1) as $r) {
            $u->roles()->attach(Role::named($r)->id);
        }

        return $u->fresh(['role', 'roles']);
    }

    public function test_added_role_opens_new_menus_on_the_very_next_request(): void
    {
        $u = $this->user('kasir');
        $this->actingAs($u);
        $this->get('/pinjaman')->assertForbidden();

        app(UserService::class)->addRole($u, 'pengurus', $this->admin);
        $this->actingAs($u->fresh());
        $this->get('/pinjaman')->assertOk();
        $this->get('/kasir')->assertOk(); // izin lama tetap (gabungan, tidak menimpa)
    }

    public function test_removed_role_closes_only_menus_no_other_role_still_grants(): void
    {
        $u = $this->user('kasir', 'pengurus');
        app(UserService::class)->removeRole($u, 'pengurus', $this->admin);
        $this->actingAs($u->fresh());
        $this->get('/pinjaman')->assertForbidden();   // hanya dari pengurus
        $this->get('/transaksi')->assertOk();         // masih dari kasir
    }

    public function test_role_permission_change_updates_every_holder(): void
    {
        $a = $this->user('pengurus');
        $b = $this->user('kasir', 'pengurus');
        app(UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['laporan', 'transaksi'])));

        foreach ([$a, $b] as $u) {
            $this->actingAs($u->fresh());
            $this->get('/laporan')->assertForbidden();
        }
        $this->actingAs($a->fresh());
        $this->get('/transaksi')->assertForbidden();
        $this->actingAs($b->fresh());
        $this->get('/transaksi')->assertOk(); // b masih dapat dari kasir
    }

    public function test_affected_user_gets_one_personal_notification_with_menu_impact(): void
    {
        $u = $this->user('kasir');
        app(UserService::class)->addRole($u, 'pengurus', $this->admin);

        $n = Notification::where('user_id', $u->id)->sole();
        $this->assertSame('Peran Anda diperbarui', $n->title);
        $this->assertStringContainsString('Ditambah: Pengurus', $n->message);
        $this->assertStringContainsString('Menu baru:', $n->message);
        $this->assertStringContainsString('Pinjaman', $n->message);
        $this->assertSame($this->admin->id, $n->created_by);
        $this->assertContains($n->id, array_column(app(NotificationService::class)->list($u->fresh()), 'id'));
        $this->assertNotContains($n->id, array_column(app(NotificationService::class)->list($this->user('kasir')), 'id')); // pribadi

        app(UserService::class)->removeRole($u->fresh(), 'pengurus', $this->admin);
        $n = Notification::where('user_id', $u->id)->sole(); // diganti, tidak menumpuk
        $this->assertStringContainsString('Dicabut: Pengurus', $n->message);
        $this->assertStringContainsString('Menu tidak lagi tersedia:', $n->message);
    }

    public function test_no_notification_when_admin_changes_own_roles_or_nothing_changes(): void
    {
        $admin = $this->user('admin');
        app(UserService::class)->addRole($admin, 'pengurus', $admin);
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());

        $u = $this->user('kasir');
        app(UserService::class)->updateRoles($u, ['kasir'], $this->admin);
        $this->assertSame(0, Notification::where('user_id', $u->id)->count());
    }

    public function test_role_permission_change_notifies_role_holders(): void
    {
        app(UserService::class)->updatePermissions(Role::named('pengurus'), [...Role::named('pengurus')->permissions, 'promo']);
        app(UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['laporan'])));

        $n = Notification::where('dedupe_key', 'role-permissions-pengurus')->sole();
        $this->assertSame('pengurus', $n->target);
        $this->assertStringContainsString('Dicabut: Laporan', $n->message);
        $holder = $this->user('kasir', 'pengurus');
        $this->assertContains($n->id, array_column(app(NotificationService::class)->list($holder), 'id'));
        $this->assertNotContains($n->id, array_column(app(NotificationService::class)->list($this->user('kasir')), 'id'));
    }

    public function test_menu_version_header_and_page_marker_change_with_roles(): void
    {
        $u = $this->user('kasir');
        $this->actingAs($u);
        $res = $this->get('/kasir')->assertOk();
        $v1 = $res->headers->get('X-Menu-Version');
        $this->assertSame(Menu::versionFor(AuthService::toArray($u)), $v1);
        $res->assertSeeHtml('data-menu-version="'.$v1.'"')->assertSeeHtml('data-access-changed');

        app(UserService::class)->addRole($u, 'pengurus', $this->admin);
        $this->actingAs($u->fresh());
        $v2 = $this->get('/kasir')->headers->get('X-Menu-Version');
        $this->assertNotSame($v1, $v2);
        $this->assertSame('"menu-'.$v2.'"', $this->getJson('/akun/menu')->headers->get('ETag'));
        $this->getJson('/akun/menu', ['If-None-Match' => '"menu-'.$v2.'"'])->assertStatus(304);
    }

    public function test_menu_check_does_not_extend_idle_session(): void
    {
        $u = $this->user('kasir');
        $this->actingAs($u)->withSession(['last_activity_at' => now()->subMinutes(5)->timestamp]);
        $before = now()->subMinutes(5)->timestamp;
        $this->getJson('/akun/menu')->assertOk();
        $this->assertSame($before, session('last_activity_at'));
    }
}
