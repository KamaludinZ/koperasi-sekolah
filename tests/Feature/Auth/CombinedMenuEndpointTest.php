<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CombinedMenuEndpointTest extends TestCase
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
            $u->roles()->attach(Role::named($r)->id);
        }

        return $u->fresh(['role', 'roles']);
    }

    public function test_menu_is_union_of_all_roles_grouped_like_the_sidebar(): void
    {
        $u = $this->user('kasir', 'pengurus');
        $this->actingAs($u);
        $res = $this->getJson('/akun/menu')->assertOk()->assertHeader('ETag');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $data = $res->json('data');
        $this->assertTrue($data['multi_role']);
        $this->assertSame([['name' => 'pengurus', 'label' => 'Pengurus', 'primary' => false], ['name' => 'kasir', 'label' => 'Kasir', 'primary' => true]], $data['roles']);
        $this->assertEqualsCanonicalizing($u->permissions(), $data['permissions']);

        // sama persis dengan menu samping (Menu::forUser) — kelompok & urutan item
        $sidebar = collect(Menu::forUser(AuthService::toArray($u)))->map(fn ($g) => array_keys($g['items']))->all();
        $this->assertSame($sidebar, collect($data['groups'])->mapWithKeys(fn ($g) => [$g['key'] => array_column($g['items'], 'key')])->all());

        $items = collect($data['groups'])->flatMap(fn ($g) => $g['items'])->keyBy('key');
        $this->assertSame('/pinjaman', $items['pinjaman']['url']);
        $this->assertSame(['pengurus'], $items['pinjaman']['from']);
        $this->assertTrue($items['pinjaman']['partial']);
        $this->assertSame(['pengurus', 'kasir'], $items['transaksi']['from']);
        $this->assertFalse($items['transaksi']['partial']);
        $this->assertArrayNotHasKey('pengguna', $items->all());
        $this->assertSame(['route' => 'kasir', 'url' => '/kasir'], $data['home']);
        $this->assertTrue($data['abilities']['kelola-shift']['allowed']);
        $this->assertFalse($data['abilities']['ubah-pengaturan']['allowed']);
    }

    public function test_single_role_account(): void
    {
        $this->actingAs($this->user('kasir'));
        $data = $this->getJson('/akun/menu')->assertOk()->json('data');
        $this->assertFalse($data['multi_role']);
        $this->assertSame(count(Role::named('kasir')->permissions), collect($data['groups'])->sum(fn ($g) => count($g['items'])));
        $this->assertFalse(collect($data['groups'])->flatMap(fn ($g) => $g['items'])->contains('partial', true));
    }

    public function test_etag_changes_when_roles_change_and_304_when_unchanged(): void
    {
        $u = $this->user('kasir');
        $this->actingAs($u);
        $etag = $this->getJson('/akun/menu')->headers->get('ETag');
        $this->getJson('/akun/menu', ['If-None-Match' => $etag])->assertStatus(304);

        app(UserService::class)->addRole($u, 'pengurus', User::factory()->admin()->create());
        $this->actingAs($u->fresh(['role', 'roles']));
        $res = $this->getJson('/akun/menu', ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $res->headers->get('ETag'));
        $this->assertContains('pinjaman', $res->json('data.permissions'));
    }

    public function test_guests_and_portal_members_cannot_read_staff_menu(): void
    {
        auth()->logout();
        $this->getJson('/akun/menu')->assertUnauthorized();

        $member = Member::factory()->create();
        $this->actingAs($member, 'member');
        $this->getJson('/akun/menu')->assertUnauthorized();
    }
}
