<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kapan anggota terakhir mengganti sendiri kata sandi & PIN portal. Null = masih memakai yang diberikan
     * pengurus (anggota wajib menggantinya di halaman Siapkan akun); diatur ulang ke null saat pengurus mengaktifkan atau
     * mengatur ulang. PIN yang dulu dibuat pengurus dikosongkan agar setiap anggota membuat PIN miliknya sendiri.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->timestamp('portal_password_changed_at')->nullable()->after('portal_last_login_at');
            $table->timestamp('portal_pin_changed_at')->nullable()->after('portal_password_changed_at');
        });
        DB::table('members')->whereNotNull('pin')->update(['pin' => null]);
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['portal_password_changed_at', 'portal_pin_changed_at']);
        });
    }
};
