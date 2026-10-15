<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Kunci idempoten (header Idempotency-Key) per pembayaran angsuran: kiriman ulang tidak mencatat pembayaran dobel. */
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->string('client_ref', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropUnique(['client_ref']);
            $table->dropColumn('client_ref');
        });
    }
};
