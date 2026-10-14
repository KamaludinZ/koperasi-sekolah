<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PIN 6 digit (tersandi) untuk memverifikasi setiap transaksi di portal anggota; masuk portal tetap dengan kata sandi. */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('pin')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('pin');
        });
    }
};
