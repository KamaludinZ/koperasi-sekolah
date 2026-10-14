<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jadwal angsuran: indeks per pinjaman+status & per pinjaman+jatuh tempo (riwayat, deteksi tunggakan),
     * dan di PostgreSQL: angsuran yang belum lunas tidak boleh dibayar penuh, pembayaran (>0) wajib punya metode &
     * tanggal bayar, serta tidak boleh ada dua angsuran pinjaman yang sama di tanggal jatuh tempo yang sama.
     */
    public function up(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->index(['loan_id', 'status']);
            $table->unique(['loan_id', 'due_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_open_not_full CHECK (status = 'dibayar' OR paid_amount < amount)");
            DB::statement('ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_payment_recorded CHECK (paid_amount = 0 OR (payment_method IS NOT NULL AND paid_at IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loan_installments DROP CONSTRAINT IF EXISTS loan_installments_open_not_full');
            DB::statement('ALTER TABLE loan_installments DROP CONSTRAINT IF EXISTS loan_installments_payment_recorded');
        }
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropUnique(['loan_id', 'due_date']);
            $table->dropIndex(['loan_id', 'status']);
        });
    }
};
