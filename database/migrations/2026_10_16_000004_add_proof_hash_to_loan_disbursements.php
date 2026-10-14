<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sidik jari SHA-256 berkas bukti pencairan: dicatat di jejak audit & diperiksa ulang saat berkas diunduh. */
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->char('proof_sha256', 64)->nullable()->after('proof_size');
        });
    }

    public function down(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->dropColumn('proof_sha256');
        });
    }
};
