<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lapisan suntingan manual & riwayat versi Pusat Panduan:
     * - guide_overrides: satu baris per (panduan, bagian) — bagian title|summary|steps|tips; value JSON. Disimpan
     *   terpisah dari isi otomatis guide_entries sehingga tidak hilang saat panduan disusun ulang;
     * - guide_versions: cuplikan isi EFEKTIF (otomatis + suntingan) tiap versi, bernomor urut per panduan, dengan
     *   jenis perubahan, catatan, dan pengubah.
     */
    public function up(): void
    {
        Schema::create('guide_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guide_entry_id')->constrained()->cascadeOnDelete();
            $table->string('field', 20); // title | summary | steps | tips
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['guide_entry_id', 'field']);
        });

        Schema::create('guide_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guide_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('change_type', 20); // generate | override | revert | restore
            $table->string('change_note', 200)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['guide_entry_id', 'version']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE guide_overrides ADD CONSTRAINT guide_overrides_field_check CHECK (field IN ('title','summary','steps','tips'))");
            DB::statement("ALTER TABLE guide_versions ADD CONSTRAINT guide_versions_check CHECK (version >= 1 AND change_type IN ('generate','override','revert','restore'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_versions');
        Schema::dropIfExists('guide_overrides');
    }
};
