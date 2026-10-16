<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Draf & versi panduan:
     * - guide_drafts: draf hasil penyusun otomatis yang TIDAK langsung diterapkan (panduan manual, atau bagian yang
     *   berubah sedang disunting admin) — menunggu ditinjau: pending → applied | discarded. Paling banyak satu draf
     *   pending per panduan (indeks unik parsial). Menyimpan isi draf, bagian yang berubah, sidik jari isi tersimpan
     *   saat draf dibuat (untuk mendeteksi draf basi), proses sinkronisasi asal, dan peninjau;
     * - guide_versions.sync_job_id: versi yang lahir dari proses sinkronisasi menunjuk ke proses itu.
     */
    public function up(): void
    {
        Schema::create('guide_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->foreignId('guide_entry_id')->nullable()->constrained()->cascadeOnDelete(); // null = panduan baru
            $table->json('payload');
            $table->json('changed_fields');
            $table->char('base_hash', 64)->nullable();
            $table->string('reason', 200)->nullable();
            $table->string('status', 10)->default('pending');
            $table->foreignId('sync_job_id')->nullable()->constrained('guide_sync_jobs')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('key');
        });
        DB::statement("CREATE UNIQUE INDEX guide_drafts_one_pending ON guide_drafts (key) WHERE status = 'pending'");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE guide_drafts ADD CONSTRAINT guide_drafts_status_check CHECK (status IN ('pending','applied','discarded'))");
        }

        Schema::table('guide_versions', function (Blueprint $table) {
            $table->foreignId('sync_job_id')->nullable()->constrained('guide_sync_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guide_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sync_job_id');
        });
        Schema::dropIfExists('guide_drafts');
    }
};
