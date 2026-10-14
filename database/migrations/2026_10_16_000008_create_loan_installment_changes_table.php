<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat perubahan angsuran: setiap perubahan baris loan_installments (pembayaran masuk, koreksi, perubahan status
     * belum/menunggak, perubahan jadwal) dicatat sebagai baris baru berisi nilai sebelum/sesudah per kolom, pelaku
     * (snapshot nama), pembayaran terkait, dan alasan. Hanya boleh ditambah: di PostgreSQL UPDATE/DELETE ditolak
     * trigger, dan pinjaman yang punya riwayat tidak bisa dihapus (FK restrict).
     */
    public function up(): void
    {
        Schema::create('loan_installment_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('loan_installment_id'); // tanpa FK: riwayat tetap utuh walau jadwal diganti
            $table->unsignedSmallInteger('sequence');
            $table->string('event', 15);                        // pembayaran | koreksi | status | jadwal
            $table->json('changes');                            // {kolom: {from, to}}
            $table->foreignId('loan_payment_id')->nullable()->constrained('loan_payments')->restrictOnDelete();
            $table->string('reason', 200)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['loan_id', 'id']);
            $table->index(['loan_installment_id', 'id']);
            $table->index(['event', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loan_installment_changes ADD CONSTRAINT loan_installment_changes_event_valid CHECK (event IN ('pembayaran','koreksi','status','jadwal'))");
            DB::statement("ALTER TABLE loan_installment_changes ADD CONSTRAINT loan_installment_changes_koreksi_reason CHECK (event <> 'koreksi' OR (reason IS NOT NULL AND length(trim(reason)) >= 5))");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION loan_installment_changes_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'loan_installment_changes hanya boleh ditambah (tidak bisa diubah/dihapus)';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER loan_installment_changes_no_update_delete
                    BEFORE UPDATE OR DELETE ON loan_installment_changes
                    FOR EACH ROW EXECUTE FUNCTION loan_installment_changes_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS loan_installment_changes_no_update_delete ON loan_installment_changes; DROP FUNCTION IF EXISTS loan_installment_changes_immutable();');
        }
        Schema::dropIfExists('loan_installment_changes');
    }
};
