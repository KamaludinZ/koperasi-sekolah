<?php

namespace Tests\Feature\Database;

use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRolesSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function ids(): array
    {
        return Role::pluck('id', 'name')->all();
    }

    public function test_structure_keys_and_cascade(): void
    {
        $this->loginAs('admin');
        $this->assertTrue(Schema::hasColumns('user_roles', ['user_id', 'role_id', 'created_at']));
        $u = User::factory()->role('kasir')->create();
        $r = $this->ids();

        try { // pasangan user+peran unik (primary key)
            DB::transaction(fn () => DB::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => $r['kasir']]));
            $this->fail('Peran ganda seharusnya ditolak.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try { // peran harus ada
            DB::transaction(fn () => DB::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => 99999]));
            $this->fail('Peran tak dikenal seharusnya ditolak.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try { // peran yang masih dipegang tidak bisa dihapus
            DB::transaction(fn () => DB::table('roles')->where('id', $r['kasir'])->delete());
            $this->fail('Peran yang dipakai seharusnya tidak bisa dihapus.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $u->delete();
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $u->id)->count()); // ikut terhapus bersama akun
    }

    public function test_pgsql_guard_blocks_removing_last_or_primary_role_but_allows_swaps(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint trigger hanya di PostgreSQL.');
        }
        $this->loginAs('admin');
        $r = $this->ids();
        $u = User::factory()->role('kasir')->create();
        $u->roles()->attach($r['pengurus'], ['created_at' => now()]);

        $attempt = function (callable $q): bool {
            DB::beginTransaction();
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            try {
                $q();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // paksa pemeriksaan "saat commit" sekarang
                DB::commit();

                return true;
            } catch (QueryException) {
                DB::rollBack();

                return false;
            }
        };
        $this->assertFalse($attempt(fn () => DB::table('user_roles')->where('user_id', $u->id)->delete()), 'semua peran dihapus');
        $this->assertFalse($attempt(fn () => DB::table('user_roles')->where('user_id', $u->id)->where('role_id', $r['kasir'])->delete()), 'peran utama dicabut tanpa ganti');
        $this->assertTrue($attempt(fn () => DB::table('user_roles')->where('user_id', $u->id)->where('role_id', $r['pengurus'])->delete()), 'peran tambahan dicabut');
        // tukar: cabut semua lalu pasang baru + ganti peran utama dalam satu transaksi → boleh
        $this->assertTrue($attempt(function () use ($u, $r) {
            DB::table('user_roles')->where('user_id', $u->id)->delete();
            DB::table('user_roles')->insert(['user_id' => $u->id, 'role_id' => $r['admin']]);
            DB::table('users')->where('id', $u->id)->update(['role_id' => $r['admin']]);
        }), 'tukar peran dalam satu transaksi');
        $this->assertSame(['admin'], $u->fresh()->roleNames());

        // lewat layanan (transaksi) tetap jalan
        app(UserService::class)->updateRoles($u->fresh(), ['kasir'], null);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(['kasir'], $u->fresh()->roleNames());
    }

    public function test_consistency_command_reports_and_repairs(): void
    {
        $this->loginAs('admin');
        $r = $this->ids();
        $missing = User::factory()->role('kasir')->create(['email' => 'hilang@koperasi.test']);
        DB::table('user_roles')->where('user_id', $missing->id)->delete();
        $wrong = User::factory()->role('kasir')->create();
        DB::table('user_roles')->insert(['user_id' => $wrong->id, 'role_id' => $r['admin']]);
        $none = User::factory()->create(['role_id' => null, 'email' => 'yatim@koperasi.test']);
        DB::table('user_roles')->where('user_id', $none->id)->delete();

        $this->artisan('pengguna:periksa-peran')->expectsOutputToContain('Peran utama belum di pivot: 1 · peran utama tidak sesuai prioritas: 1 · tanpa peran: 1')
            ->expectsOutputToContain('yatim@koperasi.test')->assertFailed();
        $this->artisan('pengguna:periksa-peran', ['--perbaiki' => true])->expectsOutputToContain('2 perbaikan diterapkan.')->assertSuccessful();

        $this->assertSame(['kasir'], $missing->fresh()->roleNames());
        $this->assertSame('admin', $wrong->fresh()->role->name);
        $this->assertSame([], $none->fresh()->roleNames()); // tidak ditebak — admin yang memilih
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.roles_repaired']);
        $this->assertSame([[], [], [$none->id]], array_values(array_intersect_key(app(UserService::class)->roleConsistency(), array_flip(['missing_pivot', 'wrong_primary', 'no_role']))));
    }

    public function test_migration_rollback_keeps_highest_role_as_single_role(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Rollback diuji di SQLite (pgsql: trigger pengaman ikut dilepas dulu).');
        }
        $this->loginAs('admin');
        $r = $this->ids();
        $u = User::factory()->role('kasir')->create();
        $u->roles()->attach($r['pengurus'], ['created_at' => now()]);
        DB::table('users')->where('id', $u->id)->update(['role_id' => $r['kasir']]);

        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_17_000002_harden_user_roles.php'])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_17_000001_create_user_roles_table.php'])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('user_roles'));
        $this->assertSame($r['pengurus'], (int) DB::table('users')->where('id', $u->id)->value('role_id'));
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_17_000001_create_user_roles_table.php'])->assertSuccessful();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_17_000002_harden_user_roles.php'])->assertSuccessful();
        $this->assertSame(['pengurus'], DB::table('user_roles')->join('roles', 'roles.id', '=', 'role_id')->where('user_id', $u->id)->pluck('name')->all());
    }
}
