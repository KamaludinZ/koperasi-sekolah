<?php

namespace Tests\Feature\Pengguna;

use App\Models\Role;
use App\Models\User;
use App\Services\PermissionMerger;
use App\Services\UserService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMergerTest extends TestCase
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

    private function merger(): PermissionMerger
    {
        return app(PermissionMerger::class);
    }

    public function test_union_without_overwrite_primary_first_then_priority_and_sources(): void
    {
        $r = $this->merger()->mergeNames(['kasir', 'pengurus'], 'kasir');
        $kasir = Role::named('kasir')->permissions;
        $pengurus = Role::named('pengurus')->permissions;

        $this->assertSame(['pengurus', 'kasir'], $r['roles']);
        $this->assertSame('kasir', $r['primary']);
        $this->assertSame($kasir, array_slice($r['permissions'], 0, count($kasir))); // peran utama dulu
        $this->assertEqualsCanonicalizing(array_values(array_unique([...$kasir, ...$pengurus])), $r['permissions']);
        $this->assertSame(count($r['permissions']), count(array_unique($r['permissions'])));
        $this->assertSame(['pengurus', 'kasir'], $r['sources']['transaksi']);
        $this->assertSame(['kasir'], $r['sources']['kasir']);
        $this->assertSame(['pengurus'], $r['sources']['pinjaman']);
    }

    public function test_adding_a_role_never_removes_permissions(): void
    {
        foreach ([['kasir'], ['pengurus'], ['admin']] as $base) {
            foreach (array_keys(Role::LABELS) as $extra) {
                $a = $this->merger()->mergeNames($base)['permissions'];
                $b = $this->merger()->mergeNames([...$base, $extra])['permissions'];
                $this->assertSame([], array_values(array_diff($a, $b)), implode('+', [...$base, $extra]));
            }
        }
    }

    public function test_unknown_menu_keys_names_and_empty_input_are_ignored(): void
    {
        Role::named('kasir')->forceFill(['permissions' => ['kasir', 'menu-hantu', 'kasir', 42]])->save();
        $r = $this->merger()->mergeNames(['kasir', 'tidak-ada', 7]);
        $this->assertSame(['kasir'], $r['permissions']);
        $this->assertSame(['kasir'], $r['roles']);

        $empty = $this->merger()->mergeNames([]);
        $this->assertSame([[], null, [], []], [$empty['roles'], $empty['primary'], $empty['permissions'], $empty['sources']]);
    }

    public function test_user_permissions_delegate_to_the_service(): void
    {
        $u = $this->user('kasir', 'pengurus');
        $this->assertSame($this->merger()->forUser($u)['permissions'], $u->permissions());
        $this->assertSame('kasir', $this->merger()->forUser($u)['primary']);
    }

    public function test_effective_access_marks_partial_menus_abilities_and_inactive_accounts(): void
    {
        $u = $this->user('kasir', 'pengurus');
        $e = $this->merger()->effective($u);
        $menus = collect($e['menus'])->keyBy('key');
        $this->assertTrue($e['active']);
        $this->assertFalse($menus['transaksi']['partial']);
        $this->assertTrue($menus['pinjaman']['partial']);
        $this->assertSame(['pengurus'], $menus['pinjaman']['from']);
        $this->assertSame('kasir', $e['home']);
        $this->assertTrue($e['abilities']['kelola-shift']['allowed']);
        $this->assertFalse($e['abilities']['ubah-pengaturan']['allowed']);

        // hak yang hanya muncul karena gabungan
        app(UserService::class)->updatePermissions(Role::named('pengurus'), ['laporan', 'audit']);
        $e = $this->merger()->effective($u->fresh(['role', 'roles']));
        $this->assertTrue($e['abilities']['setujui-retur']['combined']);

        $u->forceFill(['is_active' => false])->save();
        $e = $this->merger()->effective($u->fresh(['role', 'roles']));
        $this->assertSame([], $e['permissions']);
        $this->assertSame([], $e['menus']);
        $this->assertSame('beranda', $e['home']);
        $this->assertFalse(collect($e['abilities'])->contains('allowed', true));
    }

    public function test_effective_permissions_endpoint(): void
    {
        $u = $this->user('kasir', 'pengurus');
        $res = $this->getJson(route('pengguna.izin', $u))->assertOk();
        $res->assertJsonPath('data.user.id', $u->id)
            ->assertJsonPath('data.primary', 'kasir')
            ->assertJsonPath('data.roles', ['pengurus', 'kasir'])
            ->assertJsonPath('data.sources.pinjaman', ['pengurus']);
        $this->assertSame($u->permissions(), $res->json('data.permissions'));
        $this->assertArrayNotHasKey('password', $res->json('data.user'));
        $this->getJson('/pengguna/999999/izin')->assertNotFound();
    }

    public function test_simulation_endpoint_validates_and_simulates(): void
    {
        $this->getJson(route('pengguna.akses.simulasi', ['peran' => 'kasir,pengurus']))->assertOk()
            ->assertJsonPath('data.roles', ['pengurus', 'kasir'])
            ->assertJsonPath('data.primary', 'pengurus')
            ->assertJsonPath('data.home', 'laporan');
        $this->getJson('/pengguna/hak-akses/simulasi?peran[]=admin')->assertOk()->assertJsonPath('data.roles', ['admin']);

        $this->getJson('/pengguna/hak-akses/simulasi')->assertUnprocessable()->assertJsonValidationErrors('peran');
        $this->getJson('/pengguna/hak-akses/simulasi?peran=kasir,ratu')->assertUnprocessable()->assertJsonValidationErrors('peran.1');
        $this->getJson('/pengguna/hak-akses/simulasi?peran=kasir,kasir')->assertUnprocessable();
        $this->getJson('/pengguna/hak-akses/simulasi?peran[0][x]=1')->assertUnprocessable();
    }

    public function test_endpoints_are_admin_only(): void
    {
        $u = $this->user('kasir');
        $this->actingAs($this->user('pengurus', 'kasir'));
        $this->getJson(route('pengguna.izin', $u))->assertForbidden();
        $this->getJson(route('pengguna.akses.simulasi', ['peran' => 'kasir']))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pengguna.izin', $u))->assertUnauthorized();
    }
}
