<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ekspor laporan yang dibuat di antrean latar (queue): siapa yang meminta, parameter,
     * status (pending → processing → done/failed), dan berkas hasil di penyimpanan privat.
     * Berkas & barisnya dibersihkan otomatis setelah beberapa hari (model:prune).
     */
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);              // mis. kas_rekap
            $table->string('format', 10)->default('csv');
            $table->json('params');
            $table->string('status', 12)->default('pending');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_valid CHECK (status IN ('pending', 'processing', 'done', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
