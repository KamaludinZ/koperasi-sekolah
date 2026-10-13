<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat pemeriksaan kesehatan sistem (tiap 5 menit lewat penjadwal): status keseluruhan, status &
     * keterangan tiap komponen, dan lama pemeriksaan. Dipakai untuk strip 24 jam dan daftar gangguan.
     */
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->string('status', 10);           // ok | peringatan | gangguan
            $table->json('results');                // {kunci: {status, detail}}
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
