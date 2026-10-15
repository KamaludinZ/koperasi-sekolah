<?php

namespace Tests\Feature\Pengguna;

use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class HideUnownedMenuTest extends TestCase
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

    private function sidebarLinks(string $html): array
    {
        preg_match('/<nav[^>]*aria-label="Menu utama"[^>]*>(.*?)<\/nav>/s', $html, $m);
        preg_match_all('/href="([^"]+)"/', $m[1] ?? '', $links);

        return $links[1];
    }

    public function test_sidebar_shows_only_menus_from_held_roles(): void
    {
        $this->actingAs($this->user('kasir'));
        $links = $this->sidebarLinks($this->get('/kasir')->assertOk()->getContent());
        $this->assertContains(route('kasir'), $links);
        $this->assertNotContains(route('laporan'), $links);
        $this->assertNotContains(route('pinjaman'), $links);
        $this->assertNotContains(route('pengguna'), $links);
        $this->assertSame(count(Role::named('kasir')->permissions), count($links));
    }

    public function test_sidebar_for_multiple_roles_is_the_union_and_lists_every_role(): void
    {
        $this->actingAs($this->user('kasir', 'pengurus'));
        $res = $this->get('/kasir')->assertOk();
        $links = $this->sidebarLinks($res->getContent());
        $union = collect([...Role::named('kasir')->permissions, ...Role::named('pengurus')->permissions])->unique();

        $this->assertSame($union->count(), count($links));
        $this->assertContains(route('kasir'), $links);      // dari kasir
        $this->assertContains(route('pinjaman'), $links);   // dari pengurus
        $this->assertNotContains(route('pengguna'), $links); // dari tak satu pun
        $res->assertSeeHtml('data-user-roles>Pengurus · Kasir</span>');
    }

    public function test_menu_removed_from_a_role_disappears_unless_another_role_still_grants_it(): void
    {
        $u = $this->user('kasir', 'pengurus');
        app(UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['laporan', 'barang'])));
        $this->actingAs($u->fresh());
        $links = $this->sidebarLinks($this->get('/kasir')->getContent());
        $this->assertNotContains(route('laporan'), $links); // hanya dari pengurus → hilang
        $this->assertContains(route('barang'), $links);     // masih dari kasir
    }

    public function test_can_reach_checks_menu_and_role_guards_of_the_route(): void
    {
        $this->actingAs($this->user('kasir'));
        $this->assertTrue(Menu::canReach('kasir'));
        $this->assertTrue(Menu::canReach('anggota.show'));
        $this->assertFalse(Menu::canReach('audit'));
        $this->assertFalse(Menu::canReach('pengguna.terkunci'));
        $this->assertTrue(Menu::canReach('beranda')); // tanpa penjaga menu

        // pengurus diberi menu Pengguna tetap tak bisa ke halaman khusus Admin (peran:admin)
        app(UserService::class)->updatePermissions(Role::named('pengurus'), [...Role::named('pengurus')->permissions, 'pengguna']);
        $this->actingAs($this->user('pengurus'));
        $this->assertFalse(Menu::canReach('pengguna.terkunci'));
        $this->get('/pengguna/terkunci')->assertForbidden();
        $this->actingAs($this->user('kasir', 'admin'));
        $this->assertTrue(Menu::canReach('pengguna.terkunci'));

        auth()->logout();
        $this->assertFalse(Menu::canReach('kasir'));
    }

    public function test_menu_link_component_hides_links_to_forbidden_pages(): void
    {
        $member = Member::factory()->create();
        $tpl = '<x-menu-link route="anggota.show" :params="$id" class="x">Nama</x-menu-link>';

        $this->actingAs($this->user('pengurus'));
        $this->assertStringContainsString('href="'.route('anggota.show', $member->id).'"', Blade::render($tpl, ['id' => $member->id]));

        app(UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['anggota'])));
        $this->actingAs($this->user('pengurus'));
        $html = Blade::render($tpl, ['id' => $member->id]);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('Nama', $html);
        $this->assertStringContainsString('data-menu-hidden="anggota.show"', $html);
    }

    public function test_open_app_button_and_forbidden_page_follow_combined_roles(): void
    {
        $this->actingAs($this->user('pengurus', 'kasir'));
        $this->get('/')->assertOk()->assertSeeHtml('href="'.route('laporan').'"')->assertDontSeeHtml('href="'.route('kasir').'"');

        $this->get('/pengguna')->assertForbidden()->assertSeeText('Pengurus · Kasir');
    }

    public function test_cross_menu_links_hidden_on_pages(): void
    {
        // Admin tanpa menu Audit: tautan "Audit → Login" di Sesi aktif tidak muncul
        app(UserService::class)->updatePermissions(Role::named('admin'), array_values(array_diff(Role::named('admin')->permissions, ['audit'])));
        $this->actingAs($this->user('admin'));
        $this->get('/pengguna/sesi-aktif')->assertOk()->assertDontSeeText('Audit → Login');

        $this->actingAs($this->user('admin', 'pengurus')); // audit kembali lewat pengurus
        $this->get('/pengguna/sesi-aktif')->assertOk()->assertSeeText('Audit → Login');
    }
}
