<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kunci idempoten setor/tarik tabungan (header Idempotency-Key / client_ref): permintaan ulang dengan
     * kunci sama mengembalikan mutasi yang sudah ada, sehingga klik ganda tidak mencatat setoran/penarikan dobel.
     */
    public function up(): void
    {
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->string('client_ref', 64)->nullable()->unique()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->dropUnique(['client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
