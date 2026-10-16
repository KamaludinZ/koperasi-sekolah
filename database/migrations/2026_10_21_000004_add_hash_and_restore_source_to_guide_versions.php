<?php

use App\Models\GuideVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat versi panduan dilengkapi:
     * - content_hash: sidik jari kanonis cuplikan isi (kunci diurutkan) — mencari versi aktif & mencegah versi kembar
     *   tanpa membandingkan JSON utuh; diisi untuk versi yang sudah ada;
     * - restored_from_version: nomor versi sumber bila versi ini hasil pemulihan;
     * - indeks (guide_entry_id, content_hash) & (guide_entry_id, created_at).
     */
    public function up(): void
    {
        Schema::table('guide_versions', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('restored_from_version')->nullable();
            $table->index(['guide_entry_id', 'content_hash']);
            $table->index(['guide_entry_id', 'created_at']);
        });
        DB::table('guide_versions')->select(['id', 'snapshot'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                DB::table('guide_versions')->where('id', $r->id)->update(['content_hash' => GuideVersion::hashOf((array) json_decode((string) $r->snapshot, true))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('guide_versions', function (Blueprint $table) {
            $table->dropIndex(['guide_entry_id', 'content_hash']);
            $table->dropIndex(['guide_entry_id', 'created_at']);
            $table->dropColumn(['content_hash', 'restored_from_version']);
        });
    }
};
