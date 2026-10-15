<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sesi impersonasi (admin masuk sementara sebagai petugas/anggota portal untuk membantu). Satu baris per sesi:
     * pelaku, target (petugas ATAU anggota) + salinan nama agar riwayat tetap terbaca bila akun dihapus, alasan,
     * batas waktu, kapan & bagaimana berakhir, serta perangkat. Id = uuid yang juga disimpan di session
     * `impersonation` (App\Support\ImpersonationSession) & dirujuk jejak audit.
     * Pengaman basis data: satu sesi aktif per admin (indeks unik parsial), target sesuai jenis, urutan waktu masuk
     * akal, dan cara berakhir dari daftar tetap (CHECK di PostgreSQL).
     */
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name', 120);
            $table->string('target_type', 10); // petugas | anggota
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('target_name', 120);
            $table->string('target_label', 120)->nullable();
            $table->string('reason', 200);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable(); // manual | kedaluwarsa | keluar | dicabut | sesi_berakhir
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'started_at']);
            $table->index(['target_type', 'target_user_id']);
            $table->index(['target_type', 'target_member_id']);
            $table->index('ended_at');
        });

        // satu sesi aktif (belum berakhir) per admin — SQLite & PostgreSQL sama-sama mendukung indeks parsial
        DB::statement('CREATE UNIQUE INDEX impersonation_sessions_one_active_per_admin ON impersonation_sessions (admin_id) WHERE ended_at IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_target_check CHECK (
                (target_type = 'petugas' AND target_member_id IS NULL) OR (target_type = 'anggota' AND target_user_id IS NULL))");
            DB::statement('ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_time_check CHECK (
                expires_at > started_at AND (ended_at IS NULL OR ended_at >= started_at))');
            DB::statement("ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_end_check CHECK (
                (ended_at IS NULL AND end_reason IS NULL) OR (ended_at IS NOT NULL AND end_reason IS NOT NULL AND end_reason IN ('manual','kedaluwarsa','keluar','dicabut','sesi_berakhir')))");
            DB::statement('ALTER TABLE impersonation_sessions ADD CONSTRAINT impersonation_sessions_reason_check CHECK (char_length(btrim(reason)) >= 10)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
