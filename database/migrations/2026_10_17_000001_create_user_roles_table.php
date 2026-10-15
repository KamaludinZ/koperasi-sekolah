<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-peran petugas: pivot user_roles (satu akun bisa admin + pengurus, dst.). users.role_id tetap ada sebagai
     * peran utama (prioritas admin > pengurus > kasir) agar kode lama tetap berjalan. Akun yang sudah ada diisi dari
     * role_id-nya.
     */
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });

        DB::table('users')->whereNotNull('role_id')->orderBy('id')->each(function ($u) {
            DB::table('user_roles')->insertOrIgnore(['user_id' => $u->id, 'role_id' => $u->role_id, 'created_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
