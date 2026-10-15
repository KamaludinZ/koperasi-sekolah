<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pengaman pivot user_roles di PostgreSQL (constraint trigger DEFERRABLE INITIALLY DEFERRED — diperiksa saat
     * transaksi disimpan, sehingga ganti peran "cabut lalu pasang" dalam satu transaksi tetap boleh):
     * - akun yang masih ada tidak boleh kehilangan seluruh perannya (peran terakhir tidak bisa dihapus);
     * - peran utama akun (users.role_id) harus tetap dipegang di pivot.
     * Hapus akun (cascade) tidak terhalang.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION user_roles_guard() RETURNS trigger AS $$
            DECLARE primary_role bigint;
            BEGIN
                SELECT role_id INTO primary_role FROM users WHERE id = OLD.user_id;
                IF NOT FOUND THEN
                    RETURN NULL; -- akun ikut dihapus
                END IF;
                IF NOT EXISTS (SELECT 1 FROM user_roles WHERE user_id = OLD.user_id) THEN
                    RAISE EXCEPTION 'akun % wajib memegang minimal satu peran', OLD.user_id USING ERRCODE = '23514';
                END IF;
                IF primary_role IS NOT NULL AND NOT EXISTS (SELECT 1 FROM user_roles WHERE user_id = OLD.user_id AND role_id = primary_role) THEN
                    RAISE EXCEPTION 'peran utama akun % harus tetap dipegang', OLD.user_id USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS user_roles_keep_one ON user_roles;
            CREATE CONSTRAINT TRIGGER user_roles_keep_one AFTER DELETE ON user_roles
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION user_roles_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS user_roles_keep_one ON user_roles; DROP FUNCTION IF EXISTS user_roles_guard();');
    }
};
