<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Jumlah barang (total quantity) disimpan agar daftar riwayat tidak perlu subquery.
            $table->unsignedInteger('item_count')->default(0)->after('payment_method');
            $table->index('payment_method');
            $table->index(['created_at', 'id']); // urutan "terbaru dulu" yang stabil
            $table->index('user_id');
        });

        // Isi untuk transaksi yang sudah ada.
        DB::table('transactions')->update([
            'item_count' => DB::raw('(SELECT COALESCE(SUM(quantity), 0) FROM transaction_items WHERE transaction_items.transaction_id = transactions.id)'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['created_at', 'id']);
            $table->dropIndex(['payment_method']);
            $table->dropColumn('item_count');
        });
    }
};
