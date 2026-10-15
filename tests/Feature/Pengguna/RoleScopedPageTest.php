<?php

namespace Tests\Feature\Pengguna;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SessionService;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleScopedPageTest extends TestCase
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

    public function test_page_only_for_one_of_several_roles_opens_and_is_marked(): void
    {
        $this->actingAs($this->user('kasir', 'admin'));

        // /pengguna: khusus Admin — terbuka lewat Admin, Kasir saja tidak bisa
        $this->get('/pengguna')->assertOk()
            ->assertSeeHtml('data-page-scope')
            ->assertSeeText('Halaman khusus peran Admin')
            ->assertSeeText('peran Kasir saja tidak bisa membukanya');

        // /audit: khusus Admin & Pengurus, dibuka lewat Admin
        $this->get('/audit')->assertOk()->assertSeeText('Halaman khusus peran Admin dan Pengurus');

        // /kasir: dimiliki kedua peran → tidak ada penanda
        $this->get('/kasir')->assertOk()->assertDontSeeHtml('data-page-scope');
    }

    public function test_menu_only_from_one_role_is_marked_without_role_guard(): void
    {
        $this->actingAs($this->user('kasir', 'pengurus'));

        // Pinjaman hanya dari pengurus (kasir dilarang) → ditandai walau rute utamanya tanpa peran:
        $res = $this->get('/pinjaman')->assertOk()->assertSeeHtml('data-page-scope');
        $res->assertSeeText('terbuka lewat peran Pengurus Anda');
        $res->assertSeeHtml('data-menu-source="pinjaman"');
        $res->assertDontSeeHtml('data-menu-source="transaksi"'); // dari kedua peran
        $res->assertSeeHtml('data-menu-source="kasir"');         // hanya kasir
    }

    public function test_single_role_accounts_see_no_markers(): void
    {
        $this->actingAs($this->user('admin'));
        $this->get('/pengguna')->assertOk()->assertDontSeeHtml('data-page-scope')->assertDontSeeHtml('data-menu-source=');
    }

    public function test_page_for_a_role_not_held_is_still_forbidden(): void
    {
        $this->actingAs($this->user('kasir', 'pengurus'));
        $this->get('/pengguna')->assertForbidden()->assertSeeText('Pengurus · Kasir');
        $this->get('/pengguna/hak-akses')->assertForbidden();
    }

    public function test_page_scope_helper(): void
    {
        $u = AuthService::toArray($this->user('kasir', 'pengurus'));
        $this->assertSame(['required' => ['admin', 'pengurus'], 'via' => ['pengurus'], 'menu' => 'audit'], Menu::pageScope('audit', $u));
        $this->assertNull(Menu::pageScope('transaksi', $u));
        $this->assertSame(['kasir'], Menu::pageScope('kasir', $u)['via']);
        $this->assertNull(Menu::pageScope('pengguna', $u)); // tak satu pun peran → bukan penanda (403 yang berlaku)
        $this->assertNull(Menu::pageScope(null, $u));
        $this->assertNull(Menu::pageScope('audit', AuthService::toArray($this->user('pengurus'))));
    }

    public function test_active_sessions_list_every_role(): void
    {
        $u = $this->user('kasir', 'pengurus');
        \DB::table('sessions')->insert(['id' => str_repeat('a', 40), 'user_id' => $u->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0', 'payload' => '', 'last_activity' => now()->timestamp]);
        $row = collect(app(SessionService::class)->allActive())->firstWhere('user_id', $u->id);
        $this->assertSame(['pengurus', 'kasir'], $row['roles']);
        $this->assertSame('kasir', $row['role']);
        $this->get('/pengguna/sesi-aktif')->assertOk()->assertSeeText('Pengurus · Kasir');
    }
}
