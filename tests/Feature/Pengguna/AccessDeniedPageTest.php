<?php

namespace Tests\Feature\Pengguna;

use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessDeniedPageTest extends TestCase
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

    public function test_forbidden_page_explains_requirements_against_every_held_role(): void
    {
        $this->actingAs($this->user('kasir'));
        $res = $this->get('/laporan')->assertForbidden();

        $res->assertSeeText('Akses ditolak')
            ->assertSeeText('Menu Laporan hanya untuk peran Admin dan Pengurus.')
            ->assertSeeHtml('data-denial')
            ->assertSeeHtml('data-held-role="kasir" data-ok="0"')
            ->assertSeeText('Pengurus') // saran peran
            ->assertSeeHtml('data-suggest')
            ->assertSeeHtml('href="'.route('kasir').'"'); // pintasan halaman yang bisa dibuka
        $res->assertDontSeeHtml('href="'.route('laporan').'" class="inline-block');
    }

    public function test_role_guarded_page_lists_required_roles_and_suggestion(): void
    {
        $this->actingAs($this->user('kasir', 'pengurus'));
        $res = $this->get('/pengguna/hak-akses')->assertForbidden();
        $res->assertSeeHtml('data-held-role="pengurus" data-ok="0"')
            ->assertSeeHtml('data-held-role="kasir" data-ok="0"')
            ->assertSeeText('Peran Admin')
            ->assertSeeText('Pengurus · Kasir');

        $d = Menu::denial('pengguna.akses', AuthService::toArray($this->user('kasir', 'pengurus')));
        $this->assertSame(['admin'], $d['required']);
        $this->assertSame('pengguna', $d['menu']);
        $this->assertSame(['admin'], $d['suggest']);
        $this->assertSame(['pengurus' => false, 'kasir' => false], $d['held']);
    }

    public function test_denied_message_follows_role_permissions_in_database(): void
    {
        app(UserService::class)->updatePermissions(Role::named('kasir'), [...Role::named('kasir')->permissions, 'laporan']);
        $this->assertSame('Menu Laporan hanya untuk peran Admin, Pengurus dan Kasir.', Menu::deniedMessage('laporan'));
        $this->assertSame(['admin', 'pengurus', 'kasir'], Menu::grantingRoles('laporan'));

        // kasir kini boleh membuka laporan
        $this->actingAs($this->user('kasir'));
        $this->get('/laporan')->assertOk();
    }

    public function test_no_suggestion_when_no_role_grants_the_menu(): void
    {
        app(UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['laporan'])));
        $this->actingAs($this->user('kasir'));
        $d = Menu::denial('laporan', AuthService::toArray($this->user('kasir')));
        $this->assertSame(['admin'], $d['suggest']);
        $this->get('/laporan')->assertForbidden()->assertSeeText('Menu Laporan hanya untuk peran Admin.');
    }

    public function test_403_from_other_rules_shows_only_the_message(): void
    {
        $this->actingAs($this->user('kasir'));
        $this->assertNull(Menu::denial('beranda'));
        $d = Menu::denial('kasir', AuthService::toArray($this->user('kasir')));
        $this->assertTrue($d['held']['kasir']); // lolos → tampilan tidak menampilkan rincian

        // 403 dari aturan non-menu (mis. kebijakan data) pada rute tanpa penjaga menu: hanya pesannya
        Route::middleware('web')->get('/_uji-tolak', fn () => abort(403, 'Kasir hanya dapat melihat shift miliknya sendiri.'))->name('uji.tolak');
        $this->get('/_uji-tolak')->assertForbidden()->assertSeeText('Kasir hanya dapat melihat shift miliknya sendiri.')
            ->assertDontSeeHtml('data-denial');
    }
}
