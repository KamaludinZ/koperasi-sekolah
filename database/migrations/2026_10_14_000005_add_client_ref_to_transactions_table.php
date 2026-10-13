<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kunci idempoten checkout (header Idempotency-Key / client_ref): permintaan ulang dengan kunci sama
     * mengembalikan transaksi yang sudah ada, sehingga klik ganda/ulang jaringan tidak membuat penjualan dobel.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('client_ref', 64)->nullable()->unique()->after('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
