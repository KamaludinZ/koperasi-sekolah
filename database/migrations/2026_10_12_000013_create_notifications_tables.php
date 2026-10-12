<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notifikasi & pengumuman (PRD: type, title, message, target, is_read, created_at).
     * - type: stok (otomatis dari stok menipis/habis) | pengumuman | sistem;
     * - target: semua | admin | kasir | pengurus | anggota (pengumuman untuk disampaikan ke anggota);
     * - dedupe_key: mencegah peringatan stok yang sama dibuat berulang (mis. stok:12:habis);
     * - show_public / expires_at: pengumuman yang tampil di beranda publik & masa berlakunya.
     * Status dibaca (is_read) bersifat per petugas → tabel notification_reads
     * (read_at = sudah dibaca, dismissed_at = disembunyikan dari daftar petugas itu).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('title', 120);
            $table->text('message');
            $table->string('target', 20)->default('semua');
            $table->string('url', 255)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dedupe_key', 100)->nullable()->unique();
            $table->boolean('show_public')->default(false);
            $table->date('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['target', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['show_public', 'expires_at']);
            $table->index('product_id');
        });

        Schema::create('notification_reads', function (Blueprint $table) {
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->primary(['notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_valid CHECK (type IN ('stok','pengumuman','sistem'))");
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_target_valid CHECK (target IN ('semua','admin','kasir','pengurus','anggota'))");
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_public_only_announcement CHECK (show_public = false OR type = 'pengumuman')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
        Schema::dropIfExists('notifications');
    }
};
