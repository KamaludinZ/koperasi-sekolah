<?php

use App\Services\LoanAuditChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log audit pinjaman ber-HMAC berantai. Setiap jejak pinjaman (activity_logs kategori pinjaman) dan setiap baris
     * riwayat perubahan angsuran mendapat satu mata rantai: hmac = HMAC-SHA256(kunci aplikasi, hmac sebelumnya |
     * sumber | id | sidik isi baris sumber). Mengubah, menghapus, atau menyisipkan baris — di sumber maupun di rantai —
     * memutus rantai dan terdeteksi saat diverifikasi. Hanya boleh ditambah (trigger di PostgreSQL).
     * loan_installment_changes juga mendapat kolom checksum (HMAC per baris), sama seperti activity_logs.
     */
    public function up(): void
    {
        Schema::table('loan_installment_changes', function (Blueprint $table) {
            $table->string('checksum', 64)->nullable();
        });

        Schema::create('loan_audit_chain', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 20);                 // activity | installment_change
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->char('payload_hash', 64);                   // HMAC isi baris sumber saat dirantai
            $table->char('prev_hmac', 64)->nullable();          // null hanya untuk mata rantai pertama
            $table->char('hmac', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id']);
            $table->index(['loan_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loan_audit_chain ADD CONSTRAINT loan_audit_chain_source_valid CHECK (source_type IN ('activity', 'installment_change'))");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION loan_audit_chain_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'loan_audit_chain hanya boleh ditambah (tidak bisa diubah/dihapus)';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER loan_audit_chain_no_update_delete
                    BEFORE UPDATE OR DELETE ON loan_audit_chain
                    FOR EACH ROW EXECUTE FUNCTION loan_audit_chain_immutable();
            SQL);
        }

        if (DB::getDriverName() === 'sqlite') { // SQLite: penjaga hanya-tambah setara (seperti activity_logs)
            foreach (['loan_audit_chain', 'loan_installment_changes'] as $t) {
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$t}_no_update BEFORE UPDATE ON {$t} BEGIN SELECT RAISE(ABORT, '{$t} hanya boleh ditambah (tidak bisa diubah/dihapus)'); END;
                    CREATE TRIGGER IF NOT EXISTS {$t}_no_delete BEFORE DELETE ON {$t} BEGIN SELECT RAISE(ABORT, '{$t} hanya boleh ditambah (tidak bisa diubah/dihapus)'); END;");
            }
        }

        // rantai untuk jejak yang sudah ada (urut waktu catat)
        app(LoanAuditChain::class)->backfill();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS loan_audit_chain_no_update_delete ON loan_audit_chain; DROP FUNCTION IF EXISTS loan_audit_chain_immutable();');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['loan_audit_chain', 'loan_installment_changes'] as $t) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_update; DROP TRIGGER IF EXISTS {$t}_no_delete;");
            }
        }
        Schema::dropIfExists('loan_audit_chain');
        Schema::table('loan_installment_changes', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
