<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kunci login bertingkat per email (lihat LoginLockoutService): jumlah gagal beruntun, tingkat
     * kunci, batas waktu kunci sementara atau kunci sampai dibuka Admin, dan alamat IP percobaan.
     * Satu baris per email; dihapus saat berhasil masuk atau dibuka Admin.
     */
    public function up(): void
    {
        Schema::create('login_lockouts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->unique();             // huruf kecil
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedTinyInteger('level')->default(0);    // 0 = belum dikunci, 1–3 = tingkat kunci
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('locked_until')->nullable();        // null + level 3 = sampai dibuka Admin
            $table->timestamp('last_attempt_at')->nullable();
            $table->json('ip_addresses')->nullable();             // IP berbeda terakhir (maks. 10)
            $table->timestamps();

            $table->index(['level', 'locked_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE login_lockouts ADD CONSTRAINT login_lockouts_level_check CHECK (level BETWEEN 0 AND 3)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_lockouts');
    }
};
