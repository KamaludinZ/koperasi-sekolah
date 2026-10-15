<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skema pinjaman & angsuran untuk portal anggota dan pencatatan pembayaran:
     * - indeks loan_payments(loan_id, voided_at) untuk riwayat pembayaran sah (daftar "Pinjaman saya" sudah memakai
     *   indeks loans(member_id, applied_at) dari migrasi pembatalan);
     * - indeks unik parsial (pembayaran yang belum dikoreksi): satu potong gaji per bulan gaji per pinjaman, dan
     *   no./berita transfer tidak dipakai dua kali (tanpa beda huruf besar/kecil & spasi) — penjaga terakhir bila dua
     *   petugas menyimpan bersamaan;
     * - di PostgreSQL: rujukan sesuai metode (potong gaji = bulan YYYY-MM, transfer ≥ 3 karakter, tunai kosong).
     */
    public function up(): void
    {
        Schema::table('loan_payments', fn (Blueprint $t) => $t->index(['loan_id', 'voided_at']));

        DB::statement("CREATE UNIQUE INDEX loan_payments_salary_month_unique ON loan_payments (loan_id, reference) WHERE payment_method = 'potong_gaji' AND voided_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX loan_payments_transfer_ref_unique ON loan_payments ((lower(replace(reference, ' ', '')))) WHERE payment_method = 'transfer' AND voided_at IS NULL");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loan_payments ADD CONSTRAINT loan_payments_reference_by_method CHECK (
                (payment_method = 'potong_gaji' AND reference IS NOT NULL AND reference ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')
                OR (payment_method = 'transfer' AND reference IS NOT NULL AND length(trim(reference)) >= 3)
                OR (payment_method = 'tunai' AND reference IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loan_payments DROP CONSTRAINT IF EXISTS loan_payments_reference_by_method');
        }
        DB::statement('DROP INDEX IF EXISTS loan_payments_transfer_ref_unique');
        DB::statement('DROP INDEX IF EXISTS loan_payments_salary_month_unique');
        Schema::table('loan_payments', fn (Blueprint $t) => $t->dropIndex(['loan_id', 'voided_at']));
    }
};
