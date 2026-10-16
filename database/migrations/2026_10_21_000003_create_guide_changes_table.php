<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat perubahan panduan per bagian (guide_changes) — melengkapi cuplikan utuh di guide_versions: setiap
     * perubahan mencatat bagian (title/summary/steps/tips/related; null untuk peristiwa seluruh panduan), jenis
     * (created | generate | override | revert | draft | restore | stale | review), isi sebelum & sesudah UTUH (JSON),
     * catatan, pengubah, versi yang lahir, dan proses sinkronisasi asal. Tidak pernah diubah setelah ditulis.
     */
    public function up(): void
    {
        Schema::create('guide_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guide_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guide_version_id')->nullable()->constrained('guide_versions')->nullOnDelete();
            $table->string('field', 20)->nullable();
            $table->string('action', 12);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sync_job_id')->nullable()->constrained('guide_sync_jobs')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['guide_entry_id', 'id']);
            $table->index(['action', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE guide_changes ADD CONSTRAINT guide_changes_check CHECK (action IN ('created','generate','override','revert','draft','restore','stale','review') AND (field IS NULL OR field IN ('title','summary','steps','tips','related')))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_changes');
    }
};
