<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun portal anggota (terpisah dari akun petugas): masuk dengan kode anggota atau email + kata sandi/PIN.
     * `password` kosong = akses portal belum diaktifkan pengurus.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('email', 120)->nullable()->unique()->after('phone');
            $table->string('password')->nullable()->after('email');
            $table->timestamp('portal_last_login_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'password', 'portal_last_login_at']);
        });
    }
};
