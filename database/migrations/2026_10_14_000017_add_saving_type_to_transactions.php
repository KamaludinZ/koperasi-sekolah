<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bayar di kasir dengan potong saldo simpanan (jenis yang boleh ditarik): transaksi menyimpan jenisnya,
     * dan retur/pembatalan bisa dikembalikan ke simpanan yang sama (refund_method `simpanan`).
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('saving_type_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transaction_returns DROP CONSTRAINT IF EXISTS transaction_returns_refund_check');
            DB::statement("ALTER TABLE transaction_returns ADD CONSTRAINT transaction_returns_refund_check CHECK (refund_method IN ('tunai','qris','tabungan','simpanan','bon'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transaction_returns DROP CONSTRAINT IF EXISTS transaction_returns_refund_check');
            DB::statement("ALTER TABLE transaction_returns ADD CONSTRAINT transaction_returns_refund_check CHECK (refund_method IN ('tunai','qris','tabungan','bon'))");
        }
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saving_type_id');
        });
    }
};
