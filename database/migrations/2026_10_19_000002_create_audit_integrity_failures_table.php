<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda catatan audit yang gagal verifikasi (activity_logs sendiri hanya-tambah, jadi tandanya disimpan di
     * sini): satu baris per catatan & jenis kegagalan — checksum HMAC tidak cocok, atau rantai sesi impersonasi
     * (mulai–selesai) putus/hilang. first/last_detected_at merekam sejak kapan & terakhir terdeteksi; cleared_at
     * terisi bila pemeriksaan berikutnya tidak lagi menemukannya.
     */
    public function up(): void
    {
        Schema::create('audit_integrity_failures', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // checksum | rantai
            $table->unsignedBigInteger('activity_log_id')->nullable(); // tanpa FK: catatan bisa saja sudah dihapus paksa
            $table->uuid('impersonation_id')->nullable();
            $table->string('event', 60)->nullable();
            $table->string('detail', 200);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'activity_log_id', 'impersonation_id']);
            $table->index(['cleared_at', 'last_detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_integrity_failures');
    }
};
