<?php

namespace Tests\Feature\Pengguna;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $multi;

    private User $orphan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('admin');
        $this->multi = User::factory()->role('kasir')->create(['name' => 'Zaenal Rangkap', 'email' => 'rangkap@koperasi.test']);
        $this->multi->roles()->attach(Role::named('pengurus')->id, ['created_at' => now()]);
        app(\App\Services\UserService::class)->roleConsistency(true); // peran utama ikut prioritas → pengurus
        $this->orphan = User::factory()->create(['name' => 'Yatim Peran', 'role_id' => null, 'is_active' => false]);
        DB::table('user_roles')->where('user_id', $this->orphan->id)->delete();
    }

    private function row(array $rows, string $email): array
    {
        return collect($rows)->firstWhere('email', $email);
    }

    public function test_list_includes_all_roles_union_permissions_and_flags(): void
    {
        $res = $this->getJson(route('pengguna.list'))->assertOk()
            ->assertJsonPath('meta.stats.multi', 1)->assertJsonPath('meta.stats.no_role', 1)->assertJsonPath('meta.roles.pengurus', 'Pengurus');
        $m = $this->row($res->json('data'), 'rangkap@koperasi.test');
        $this->assertSame(['pengurus', 'kasir'], $m['roles']);
        $this->assertSame('pengurus', $m['primary_role']);
        $this->assertSame([['name' => 'pengurus', 'label' => 'Pengurus', 'primary' => true], ['name' => 'kasir', 'label' => 'Kasir', 'primary' => false]], $m['roles_detail']);
        $this->assertTrue($m['is_multi_role']);
        $this->assertFalse($m['has_no_role']);
        $this->assertContains('kasir', $m['permissions']);
        $this->assertContains('pinjaman', $m['permissions']);
        $this->assertArrayNotHasKey('password', $m);

        $o = $this->row($res->json('data'), $this->orphan->email);
        $this->assertSame([[], [], true, false], [$o['roles'], $o['permissions'], $o['has_no_role'], $o['is_active']]);
    }

    public function test_filters(): void
    {
        $emails = fn (array $q) => collect($this->getJson(route('pengguna.list', $q))->assertOk()->json('data'))->pluck('email')->all();
        $this->assertContains('rangkap@koperasi.test', $emails(['peran' => 'kasir']));
        $this->assertContains('rangkap@koperasi.test', $emails(['peran' => 'pengurus']));
        $this->assertNotContains('rangkap@koperasi.test', $emails(['peran' => 'admin']));
        $this->assertSame([$this->orphan->email], $emails(['peran' => 'tanpa']));
        $this->assertSame(['rangkap@koperasi.test'], $emails(['multi' => 1]));
        $inactive = $emails(['status' => 'nonaktif']);
        $this->assertContains($this->orphan->email, $inactive);
        $this->assertSame(User::where('is_active', false)->count(), count($inactive));
        $this->assertNotContains('rangkap@koperasi.test', $inactive);
        $this->assertNotContains($this->orphan->email, $emails(['status' => 'aktif']));
        $this->assertSame(['rangkap@koperasi.test'], $emails(['q' => 'zaenal']));
        foreach ([['peran' => 'tamu'], ['status' => 'hapus'], ['multi' => 'ya'], ['q' => str_repeat('a', 101)]] as $bad) {
            $this->getJson(route('pengguna.list', $bad))->assertUnprocessable();
        }
    }

    public function test_show_endpoint(): void
    {
        $this->getJson(route('pengguna.show', $this->multi))->assertOk()
            ->assertJsonPath('data.email', 'rangkap@koperasi.test')->assertJsonPath('data.roles', ['pengurus', 'kasir'])->assertJsonPath('data.is_multi_role', true);
        $this->getJson('/pengguna/999999/data')->assertNotFound();
    }

    public function test_store_and_update_responses_include_roles(): void
    {
        $this->postJson('/pengguna', ['name' => 'Baru', 'email' => 'baru@koperasi.test', 'roles' => ['kasir', 'admin'], 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'])
            ->assertCreated()->assertJsonPath('data.roles', ['admin', 'kasir'])->assertJsonPath('data.primary_role', 'admin')->assertJsonPath('data.is_multi_role', true);
    }

    public function test_only_admin(): void
    {
        $this->loginAs('pengurus');
        $this->getJson(route('pengguna.list'))->assertForbidden();
        $this->getJson(route('pengguna.show', $this->multi))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pengguna.list'))->assertUnauthorized();
    }
}
