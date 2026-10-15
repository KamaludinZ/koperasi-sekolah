<?php

namespace Tests\Feature\Database;

use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImpersonationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
    }

    private function row(array $override = []): array
    {
        return [
            'id' => (string) Str::uuid(), 'admin_id' => $this->admin->id, 'admin_name' => $this->admin->name,
            'target_type' => 'petugas', 'target_user_id' => $this->kasir->id, 'target_member_id' => null,
            'target_name' => $this->kasir->name, 'target_label' => 'Kasir', 'reason' => 'membantu kasir memeriksa struk',
            'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
            ...$override,
        ];
    }

    private function fails(callable $fn): bool
    {
        try {
            DB::transaction($fn);

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('impersonation_sessions', [
            'id', 'admin_id', 'admin_name', 'target_type', 'target_user_id', 'target_member_id', 'target_name', 'target_label',
            'reason', 'started_at', 'expires_at', 'ended_at', 'end_reason', 'ip_address', 'user_agent', 'created_at', 'updated_at',
        ]));
    }

    public function test_model_casts_relations_and_scopes(): void
    {
        $i = Impersonation::create(collect($this->row())->except(['id', 'created_at', 'updated_at'])->all());
        $this->assertTrue(Str::isUuid($i->id));
        $this->assertTrue($i->isActive());
        $this->assertTrue($i->admin->is($this->admin));
        $this->assertTrue($i->target()->is($this->kasir));
        $this->assertSame(1, Impersonation::active()->count());

        $this->travel(31)->minutes();
        $this->assertFalse($i->fresh()->isActive());
        $this->assertSame(0, Impersonation::active()->count());
        $this->assertSame(1, Impersonation::open()->count()); // lewat waktu tapi belum ditutup

        $i->update(['ended_at' => now(), 'end_reason' => 'kedaluwarsa']);
        $this->assertSame(0, Impersonation::open()->count());
        $this->assertSame(31, $i->fresh()->durationMinutes());
    }

    public function test_member_target_relation_survives_soft_delete(): void
    {
        $m = Member::factory()->create();
        $i = Impersonation::create(collect($this->row(['target_type' => 'anggota', 'target_user_id' => null, 'target_member_id' => $m->id, 'target_name' => $m->name]))->except(['id', 'created_at', 'updated_at'])->all());
        $m->delete();
        $this->assertTrue($i->fresh()->target()->is($m));
    }

    public function test_only_one_open_session_per_admin(): void
    {
        DB::table('impersonation_sessions')->insert($this->row());
        $this->assertTrue($this->fails(fn () => DB::table('impersonation_sessions')->insert($this->row())));

        DB::table('impersonation_sessions')->update(['ended_at' => now(), 'end_reason' => 'manual']);
        DB::table('impersonation_sessions')->insert($this->row()); // setelah yang lama berakhir, boleh mulai lagi
        $this->assertSame(2, DB::table('impersonation_sessions')->count());
    }

    public function test_history_survives_deleting_admin_or_target(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Sementara']);
        $target = User::factory()->role('kasir')->create(['name' => 'Kasir Sementara']);
        DB::table('impersonation_sessions')->insert($this->row(['admin_id' => $admin->id, 'admin_name' => $admin->name, 'target_user_id' => $target->id, 'target_name' => $target->name]));
        $target->delete();
        $admin->delete();
        $row = DB::table('impersonation_sessions')->first();
        $this->assertNull($row->admin_id);
        $this->assertNull($row->target_user_id);
        $this->assertSame(['Admin Sementara', 'Kasir Sementara'], [$row->admin_name, $row->target_name]);
    }

    public function test_postgres_checks_reject_inconsistent_rows(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya dipasang di PostgreSQL.');
        }
        $m = Member::factory()->create();
        $this->assertTrue($this->fails(fn () => DB::table('impersonation_sessions')->insert($this->row(['target_member_id' => $m->id]))), 'petugas + anggota sekaligus');
        $this->assertTrue($this->fails(fn () => DB::table('impersonation_sessions')->insert($this->row(['target_type' => 'anggota']))), 'anggota tapi target petugas');
        $this->assertTrue($this->fails(fn () => DB::table('impersonation_sessions')->insert($this->row(['expires_at' => now()->subMinute()]))), 'batas sebelum mulai');
        $this->assertTrue($this->fails(fn () => DB::table('impersonation_sessions')->insert($this->row(['ended_at' => now()]))), 'berakhir tanpa alasan');
        $this->assertTrue($this->fails(fn () => DB::table('impersonation_sessions')->insert($this->row(['ended_at' => now(), 'end_reason' => 'ngawur']))), 'alasan berakhir asing');
        $this->assertTrue($this->fails(fn () => DB::table('impersonation_sessions')->insert($this->row(['reason' => '   pendek   ']))), 'alasan terlalu pendek');
        $this->assertSame(0, DB::table('impersonation_sessions')->count());
    }

    public function test_migration_rolls_back_cleanly(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Rollback diuji di SQLite.');
        }
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_18_000001_create_impersonation_sessions_table.php'])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('impersonation_sessions'));
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_18_000001_create_impersonation_sessions_table.php'])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('impersonation_sessions'));
    }
}
