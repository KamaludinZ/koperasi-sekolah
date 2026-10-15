<?php

namespace Tests\Feature\Pengguna;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveRoleIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->role($roles[0])->create();
        foreach (array_slice($roles, 1) as $r) {
            $u->roles()->attach(Role::named($r)->id, ['created_at' => now()]);
        }

        return $u->fresh(['role', 'roles']);
    }

    public function test_indicator_lists_every_active_role_with_primary_and_menu_counts(): void
    {
        $this->actingAs($this->user('kasir', 'pengurus'));
        $kasir = count(Role::named('kasir')->permissions);
        $pengurus = count(Role::named('pengurus')->permissions);
        $union = collect([...Role::named('kasir')->permissions, ...Role::named('pengurus')->permissions])->unique()->count();

        $this->get('/kasir')->assertOk()
            ->assertSeeHtml('data-user-roles>Pengurus · Kasir</span>')
            ->assertSeeHtml('data-active-role-count>2 peran</span>')
            ->assertSeeHtmlInOrder(['data-role-panel', 'data-active-role="pengurus"', $pengurus.' menu', 'data-active-role="kasir"', 'utama', $kasir.' menu'])
            ->assertSeeText("Hak akses = gabungan {$union} menu dari semua peran Anda.")
            ->assertDontSeeText('Lihat rincian hak akses'); // simulator khusus admin
    }

    public function test_single_role_has_no_count_badge_and_admin_gets_detail_link(): void
    {
        $this->actingAs($this->user('admin'));
        $this->get('/pengguna')->assertOk()
            ->assertSeeHtml('data-user-roles>Admin</span>')
            ->assertDontSeeHtml('data-active-role-count')
            ->assertSeeText('Lihat rincian hak akses')
            ->assertSeeHtml('href="'.route('pengguna.akses', ['peran' => 'admin']).'"');
    }

    public function test_indicator_follows_role_changes_on_next_page(): void
    {
        $u = $this->user('kasir');
        $this->actingAs($u);
        $this->get('/kasir')->assertDontSeeHtml('data-active-role="pengurus"');

        $u->roles()->attach(Role::named('pengurus')->id, ['created_at' => now()]);
        $this->actingAs($u->fresh(['role', 'roles']));
        $this->get('/kasir')->assertSeeHtml('data-active-role="pengurus"')->assertSeeHtml('data-active-role-count>2 peran</span>');
    }
}
