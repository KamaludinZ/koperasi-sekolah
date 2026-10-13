<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat cadangan data: satu baris per arsip cadangan (terjadwal/manual) dengan jenis isi
     * (lengkap = basis data + berkas, atau basis data saja), status berjalan → berhasil/gagal, lokasi
     * berkas di disk, ukuran, durasi, checksum SHA-256, isi arsip, catatan, galat, dan pembuat.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();          // nama berkas arsip
            $table->string('disk', 30)->default('local');
            $table->string('path', 255)->nullable();        // lokasi arsip di disk (null bila gagal)
            $table->string('type', 12);                     // lengkap | basis data
            $table->string('trigger', 12);                  // terjadwal | manual
            $table->string('status', 10)->default('berjalan'); // berjalan | berhasil | gagal
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('duration_s')->default(0);
            $table->char('checksum', 64)->nullable();       // SHA-256 arsip
            $table->json('includes')->nullable();           // daftar isi arsip
            $table->string('note', 200)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('started_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_status_check CHECK (status IN ('berjalan','berhasil','gagal'))");
            DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_type_check CHECK (type IN ('lengkap','basis data'))");
            DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_trigger_check CHECK (trigger IN ('terjadwal','manual'))");
            DB::statement("ALTER TABLE backups ADD CONSTRAINT backups_done_check CHECK ((status = 'berhasil') = (path IS NOT NULL AND checksum IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
