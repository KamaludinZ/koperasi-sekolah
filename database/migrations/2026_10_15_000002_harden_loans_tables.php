<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pengaman tambahan tabel pinjaman (PostgreSQL):
     * - sisa pinjaman tidak boleh melebihi pokok + bunga;
     * - pembayaran angsuran tidak boleh melebihi nominal angsurannya;
     * - angsuran berstatus "dibayar" wajib punya tanggal bayar & dibayar penuh;
     * - pinjaman lunas bersisa nol, pinjaman berjalan/menunggak wajib punya tanggal cair.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_outstanding_within_total CHECK (outstanding <= principal + interest)');
        DB::statement("ALTER TABLE loans ADD CONSTRAINT loans_status_consistent CHECK ((status <> 'lunas' OR outstanding = 0) AND (status NOT IN ('berjalan','menunggak','lunas') OR disbursed_at IS NOT NULL))");
        DB::statement('ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_paid_within_amount CHECK (paid_amount <= amount)');
        DB::statement("ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_paid_consistent CHECK (status <> 'dibayar' OR (paid_at IS NOT NULL AND paid_amount = amount))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_outstanding_within_total');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_status_consistent');
        DB::statement('ALTER TABLE loan_installments DROP CONSTRAINT IF EXISTS loan_installments_paid_within_amount');
        DB::statement('ALTER TABLE loan_installments DROP CONSTRAINT IF EXISTS loan_installments_paid_consistent');
    }
};
