<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Rujukan pembayaran per metode: no./berita transfer, atau periode gaji (Y-m) untuk potong gaji. */
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->string('reference', 100)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
