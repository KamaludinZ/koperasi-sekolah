<?php

use App\Support\Menu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peran petugas (admin, kasir, pengurus) dengan daftar menu yang boleh dibuka,
     * lalu tambahkan peran, status aktif, dan waktu login/ganti sandi ke tabel users.
     * Tiga peran bawaan langsung diisi karena wajib ada (data referensi).
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();   // admin | kasir | pengurus
            $table->string('label', 50);
            $table->json('permissions');            // daftar kunci menu, mis. ["kasir","transaksi"]
            $table->timestamps();
        });

        $labels = ['admin' => 'Admin', 'kasir' => 'Kasir', 'pengurus' => 'Pengurus'];
        foreach (Menu::DEFAULT_PERMISSIONS as $name => $menus) {
            DB::table('roles')->insert([
                'name' => $name, 'label' => $labels[$name], 'permissions' => json_encode($menus),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->after('role_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            $table->index(['is_active', 'role_id']);
        });

        // Akun lama tanpa peran → kasir (peran paling terbatas yang bisa bertransaksi).
        DB::table('users')->whereNull('role_id')->update(['role_id' => DB::table('roles')->where('name', 'kasir')->value('id')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'role_id']);
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['is_active', 'last_login_at', 'password_changed_at']);
        });

        Schema::dropIfExists('roles');
    }
};
