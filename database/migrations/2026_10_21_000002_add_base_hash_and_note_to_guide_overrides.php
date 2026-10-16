<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lapisan override panduan dilengkapi:
     * - base_hash: sidik jari isi OTOMATIS bagian itu saat disunting — bila penyusun otomatis kemudian mengubah isi
     *   bagian tersebut, editor bisa memberi tahu bahwa suntingan disusun di atas isi otomatis yang sudah berubah;
     * - note: catatan suntingan terakhir (juga tercatat di versi & audit);
     * - indeks updated_by untuk daftar suntingan per petugas.
     */
    public function up(): void
    {
        Schema::table('guide_overrides', function (Blueprint $table) {
            $table->char('base_hash', 64)->nullable()->after('value');
            $table->string('note', 200)->nullable()->after('base_hash');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('guide_overrides', function (Blueprint $table) {
            $table->dropIndex(['updated_by']);
            $table->dropColumn(['base_hash', 'note']);
        });
    }
};
