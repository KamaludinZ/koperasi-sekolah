<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat uji pemulihan: cadangan yang diuji, status (berjalan/berhasil/gagal), langkah yang lolos,
     * perbandingan jumlah baris per tabel (di arsip, berhasil dipulihkan, data sekarang), jumlah tabel
     * yang tidak cocok, galat, dan petugas yang menjalankan.
     */
    public function up(): void
    {
        Schema::create('restore_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('backup_name', 120);
            $table->timestamp('backup_at')->nullable();
            $table->string('status', 10)->default('berjalan');
            $table->json('steps')->nullable();
            $table->json('tables')->nullable();
            $table->unsignedInteger('mismatches')->default(0);
            $table->unsignedInteger('duration_s')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE restore_tests ADD CONSTRAINT restore_tests_status_check CHECK (status IN ('berjalan','berhasil','gagal'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_tests');
    }
};
