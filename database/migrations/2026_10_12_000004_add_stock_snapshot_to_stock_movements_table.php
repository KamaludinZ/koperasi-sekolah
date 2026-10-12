<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Stok sebelum & sesudah mutasi, agar riwayat bisa ditelusuri tanpa hitung ulang.
            $table->integer('stock_before')->nullable()->after('quantity');
            $table->integer('stock_after')->nullable()->after('stock_before');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['stock_before', 'stock_after']);
        });
    }
};
