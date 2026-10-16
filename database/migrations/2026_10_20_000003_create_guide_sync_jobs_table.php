<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat sinkronisasi Pusat Panduan (guide_sync_jobs): satu baris per penyusunan ulang panduan otomatis —
     * status (queued → running → done | failed), pemicu (scheduled | menu_changed | permission_changed | manual),
     * pemicu manusia bila ada, cakupan (kunci panduan tertentu atau semua), waktu mulai/selesai, hasil hitungan,
     * dan pesan galat singkat.
     */
    public function up(): void
    {
        Schema::create('guide_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 10)->default('queued');
            $table->string('trigger', 20);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('scope')->nullable();   // null = semua panduan; daftar kunci bila sebagian
            $table->json('stats')->nullable();   // {created, updated, unchanged, skipped_manual, stale}
            $table->string('note', 200)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE guide_sync_jobs ADD CONSTRAINT guide_sync_jobs_check CHECK (status IN ('queued','running','done','failed') AND \"trigger\" IN ('scheduled','menu_changed','permission_changed','manual'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_sync_jobs');
    }
};
