<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjaga tingkat database untuk tabel activity_logs (hanya-tambah):
 * - PostgreSQL: trigger menolak UPDATE/DELETE per baris dan TRUNCATE;
 * - SQLite: trigger RAISE(ABORT) untuk UPDATE/DELETE.
 * suspend() mematikan penjaga sementara khusus untuk migrasi pengisian data.
 */
class AuditGuard
{
    public const MESSAGE = 'activity_logs hanya boleh ditambah (tidak bisa diubah/dihapus)';

    public static function install(): void
    {
        $msg = self::MESSAGE;
        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION activity_logs_immutable() RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION '{$msg}';
                END;
                \$\$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS activity_logs_no_update_delete ON activity_logs;
                CREATE TRIGGER activity_logs_no_update_delete
                    BEFORE UPDATE OR DELETE ON activity_logs
                    FOR EACH ROW EXECUTE FUNCTION activity_logs_immutable();

                DROP TRIGGER IF EXISTS activity_logs_no_truncate ON activity_logs;
                CREATE TRIGGER activity_logs_no_truncate
                    BEFORE TRUNCATE ON activity_logs
                    FOR EACH STATEMENT EXECUTE FUNCTION activity_logs_immutable();
            SQL),
            'sqlite' => DB::unprepared(<<<SQL
                CREATE TRIGGER IF NOT EXISTS activity_logs_no_update BEFORE UPDATE ON activity_logs
                BEGIN SELECT RAISE(ABORT, '{$msg}'); END;
                CREATE TRIGGER IF NOT EXISTS activity_logs_no_delete BEFORE DELETE ON activity_logs
                BEGIN SELECT RAISE(ABORT, '{$msg}'); END;
            SQL),
            default => null,
        };
    }

    /** Lepas penjaga tambahan (dipakai rollback migrasi); trigger UPDATE/DELETE PostgreSQL bawaan tetap. */
    public static function uninstall(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared('DROP TRIGGER IF EXISTS activity_logs_no_truncate ON activity_logs;'),
            'sqlite' => DB::unprepared('DROP TRIGGER IF EXISTS activity_logs_no_update; DROP TRIGGER IF EXISTS activity_logs_no_delete;'),
            default => null,
        };
    }

    /**
     * Jalankan $callback dengan penjaga dimatikan sementara (hanya untuk migrasi).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function suspend(callable $callback): mixed
    {
        $driver = DB::getDriverName();
        $sqliteGuarded = $driver === 'sqlite' && self::sqliteGuarded();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE activity_logs DISABLE TRIGGER USER');
        } elseif ($sqliteGuarded) {
            self::uninstall();
        }

        try {
            return $callback();
        } finally {
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE activity_logs ENABLE TRIGGER USER');
            } elseif ($sqliteGuarded) {
                self::install();
            }
        }
    }

    private static function sqliteGuarded(): bool
    {
        return Schema::hasTable('activity_logs')
            && DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'activity_logs_no_update')->exists();
    }
}
