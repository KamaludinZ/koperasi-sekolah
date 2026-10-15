<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relasi pengguna–peran dilengkapi pemberi peran: user_roles.granted_by = petugas yang memasang peran itu
     * (null untuk data lama/seeder/perintah konsol, atau bila akun pemberinya sudah dihapus). Bersama created_at,
     * tiap baris pivot menjawab "sejak kapan & oleh siapa akun ini memegang peran ini".
     */
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->foreignId('granted_by')->nullable()->after('role_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('granted_by');
        });
    }
};
