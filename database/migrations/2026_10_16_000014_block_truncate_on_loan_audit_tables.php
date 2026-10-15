<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** PostgreSQL: TRUNCATE (yang tidak memicu trigger per baris) ikut ditolak pada tabel log audit pinjaman. */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION loan_audit_no_truncate() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'log audit pinjaman tidak boleh dikosongkan (TRUNCATE)';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS loan_audit_chain_no_truncate ON loan_audit_chain;
            CREATE TRIGGER loan_audit_chain_no_truncate BEFORE TRUNCATE ON loan_audit_chain FOR EACH STATEMENT EXECUTE FUNCTION loan_audit_no_truncate();
            DROP TRIGGER IF EXISTS loan_installment_changes_no_truncate ON loan_installment_changes;
            CREATE TRIGGER loan_installment_changes_no_truncate BEFORE TRUNCATE ON loan_installment_changes FOR EACH STATEMENT EXECUTE FUNCTION loan_audit_no_truncate();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS loan_audit_chain_no_truncate ON loan_audit_chain;
            DROP TRIGGER IF EXISTS loan_installment_changes_no_truncate ON loan_installment_changes;
            DROP FUNCTION IF EXISTS loan_audit_no_truncate();');
    }
};
