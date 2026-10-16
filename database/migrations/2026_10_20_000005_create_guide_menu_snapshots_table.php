<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata menu & izin yang terakhir terbaca pemindai panduan (guide_menu_snapshots): satu baris per menu —
     * label, rute utama, peran pemegang (roles.permissions), halaman (nama, judul, alamat, khusus peran, izin aksi),
     * dan sidik jari SHA-256 per aspek (menu, izin, halaman) sehingga perubahan bisa dideteksi dan panduan yang
     * terdampak ditandai usang / disusun ulang.
     */
    public function up(): void
    {
        Schema::create('guide_menu_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('menu_key', 60)->unique();
            $table->string('label', 100);
            $table->string('route', 100)->nullable();
            $table->json('roles');
            $table->json('pages');
            $table->char('menu_hash', 64);
            $table->char('roles_hash', 64);
            $table->char('pages_hash', 64);
            $table->timestamp('scanned_at');
            $table->timestamp('changed_at')->nullable(); // terakhir salah satu sidik jari berubah
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_menu_snapshots');
    }
};
