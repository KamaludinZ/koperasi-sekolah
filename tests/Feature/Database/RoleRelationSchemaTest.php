<?php

namespace Tests\Feature\Database;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\UserService;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleRelationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    public function test_pivot_has_composite_key_granted_by_and_created_at(): void
    {
        $this->assertTrue(Schema::hasColumns('user_roles', ['user_id', 'role_id', 'granted_by', 'created_at']));
        $u = User::factory()->role('kasir')->create();
        $this->expectException(QueryException::class);
        DB::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => Role::named('kasir')->id]); // duplikat (kunci gabungan)
    }

    public function test_roles_granted_by_service_record_granter_and_keep_history_of_unchanged_roles(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $u = app(UserService::class)->create(['name' => 'Rangkap', 'email' => 'rangkap@koperasi.test', 'password' => 'rahasia-panjang-1', 'roles' => ['kasir']]);
        $kasirRow = DB::table('user_roles')->where('user_id', $u->id)->first();
        $this->assertSame($admin->id, (int) $kasirRow->granted_by);

        // admin lain menambah pengurus: baris kasir tetap (waktu & pemberi lama), baris pengurus milik admin kedua
        $this->travel(2)->days();
        $admin2 = User::factory()->admin()->create();
        $this->actingAs($admin2);
        app(UserService::class)->updateRoles($u->fresh(), ['kasir', 'pengurus'], $admin2);

        $rows = DB::table('user_roles')->join('roles', 'roles.id', '=', 'role_id')->where('user_id', $u->id)->get(['roles.name', 'granted_by', 'user_roles.created_at'])->keyBy('name');
        $this->assertSame($admin->id, (int) $rows['kasir']->granted_by);
        $this->assertSame((string) $kasirRow->created_at, (string) $rows['kasir']->created_at); // tidak tertimpa
        $this->assertSame($admin2->id, (int) $rows['pengurus']->granted_by);
        $this->assertNotSame((string) $kasirRow->created_at, (string) $rows['pengurus']->created_at);
    }

    public function test_granter_deleted_keeps_role_with_null_granter(): void
    {
        $granter = User::factory()->admin()->create();
        $this->actingAs($granter);
        $u = User::factory()->role('kasir')->create();
        $u->roles()->attach(Role::named('pengurus')->id);
        $this->assertSame($granter->id, (int) DB::table('user_roles')->where('user_id', $u->id)->where('role_id', Role::named('pengurus')->id)->value('granted_by'));

        auth()->logout();
        $granter->roles()->detach(); // lepas dulu agar penghapusan akun tidak terhalang pengaman lain
        $granter->delete();
        $this->assertSame(['pengurus', 'kasir'], $u->fresh()->roleNames());
        $this->assertNull(DB::table('user_roles')->where('user_id', $u->id)->where('role_id', Role::named('pengurus')->id)->value('granted_by'));
    }

    public function test_console_and_seeder_grants_have_no_granter(): void
    {
        auth()->logout();
        $u = User::factory()->role('pengurus')->create();
        $this->assertNull(DB::table('user_roles')->where('user_id', $u->id)->value('granted_by'));
        $this->assertNull(UserRole::currentGranter());
    }

    public function test_holding_role_scope_uses_every_role(): void
    {
        $multi = User::factory()->role('kasir')->create();
        $multi->roles()->attach(Role::named('admin')->id);
        $ids = User::holdingRole('admin')->pluck('id');
        $this->assertContains($multi->id, $ids->all());
        $this->assertNotContains($multi->id, User::holdingRole('pengurus')->pluck('id')->all());
        $this->assertContains($multi->id, User::holdingRole('pengurus', 'kasir')->pluck('id')->all());
    }

    public function test_role_in_use_cannot_be_deleted_and_user_delete_cascades(): void
    {
        $u = User::factory()->role('kasir')->create();
        try {
            DB::transaction(fn () => DB::table('roles')->where('name', 'kasir')->delete());
            $this->fail('peran yang masih dipakai tidak boleh terhapus');
        } catch (QueryException) {
            $this->assertTrue(DB::table('roles')->where('name', 'kasir')->exists());
        }
        $u->delete();
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $u->id)->count());
    }

    public function test_user_detail_endpoint_shows_since_and_granter(): void
    {
        $admin = User::where('email', 'admin@koperasi.test')->first() ?? User::factory()->admin()->create();
        $this->actingAs($admin);
        $u = User::factory()->role('kasir')->create();
        app(UserService::class)->addRole($u, 'pengurus', $admin);

        $detail = collect($this->getJson(route('pengguna.show', $u))->assertOk()->json('data.roles_detail'))->keyBy('name');
        $this->assertSame(['id' => $admin->id, 'name' => $admin->name], $detail['pengurus']['granted_by']);
        $this->assertNotNull($detail['kasir']['granted_at']);
    }
}
