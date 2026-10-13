<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditChangeColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_13_000009_add_change_columns_to_activity_logs_table.php';

    public function test_columns_exist_and_field_changes_are_paired(): void
    {
        $this->assertTrue(Schema::hasColumns('activity_logs', ['action', 'old_values', 'new_values', 'reason']));

        $log = ActivityLog::create([
            'event' => 'product.updated', 'category' => 'barang', 'description' => 'Barang diubah', 'action' => 'diubah',
            'old_values' => ['price' => 2500, 'name' => 'Pensil'], 'new_values' => ['price' => 3000, 'unit' => 'pcs'],
            'reason' => 'Harga pemasok naik',
        ])->fresh();

        $this->assertSame([
            'price' => ['from' => 2500, 'to' => 3000],
            'name' => ['from' => 'Pensil', 'to' => null],
            'unit' => ['from' => null, 'to' => 'pcs'],
        ], $log->field_changes);
        $this->assertSame('Harga pemasok naik', $log->reason);
        $this->assertSame(1, ActivityLog::masterChanges()->count());
        ActivityLog::create(['event' => 'auth.login', 'category' => 'auth', 'description' => 'Masuk']);
        $this->assertSame(1, ActivityLog::masterChanges()->count());
    }

    public function test_backfill_moves_existing_changes_and_keeps_rows_immutable(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $this->assertFalse(Schema::hasColumn('activity_logs', 'action'));

        $insert = fn (string $event, string $category, array $props) => DB::table('activity_logs')->insertGetId([
            'event' => $event, 'category' => $category, 'description' => $event, 'properties' => json_encode($props), 'created_at' => now(),
        ]);
        $updated = $insert('product.updated', 'barang', ['changes' => ['price' => ['from' => 2500, 'to' => 3000]]]);
        $deleted = $insert('member.deleted', 'anggota', []);
        $login = $insert('auth.login', 'auth', []);

        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        $u = ActivityLog::find($updated);
        $this->assertSame('diubah', $u->action);
        $this->assertSame(['price' => 2500], $u->old_values);
        $this->assertSame(['price' => 3000], $u->new_values);
        $this->assertSame('dihapus', ActivityLog::find($deleted)->action);
        $this->assertNull(ActivityLog::find($deleted)->old_values);
        $this->assertNull(ActivityLog::find($login)->action);

        if (DB::getDriverName() === 'pgsql') {
            $this->expectException(QueryException::class);
            DB::table('activity_logs')->where('id', $updated)->update(['reason' => 'ubah diam-diam']);
        }
    }

    public function test_pgsql_rejects_unknown_action(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint khusus PostgreSQL.');
        }
        $this->expectException(QueryException::class);
        ActivityLog::create(['event' => 'x', 'category' => 'barang', 'description' => 'x', 'action' => 'dimusnahkan']);
    }
}
