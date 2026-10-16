<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konten Pusat Panduan: satu baris per panduan (petugas: per menu Menu::ITEMS + panduan umum; anggota: portal.*).
     * steps/tips/related/required_permissions disimpan JSON; source = auto (dari katalog/penyusun otomatis) atau manual;
     * is_stale menandai panduan yang perlu ditinjau setelah menu/fitur/izin berubah; position = urutan tampil.
     */
    public function up(): void
    {
        Schema::create('guide_entries', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();               // mis. kasir, akun, portal.belanja
            $table->string('menu_key', 40)->nullable()->index(); // kunci Menu::ITEMS; null = panduan umum/anggota
            $table->string('audience', 10)->default('petugas');  // petugas | anggota
            $table->string('title', 150);
            $table->text('summary');
            $table->json('steps');                               // [{text, route?}]
            $table->json('tips')->nullable();                    // [string]
            $table->json('related')->nullable();                 // {route: label}
            $table->text('keywords')->nullable();
            $table->json('required_permissions')->nullable();    // kunci menu yang dibutuhkan
            $table->string('source', 10)->default('auto');       // auto | manual
            $table->boolean('is_stale')->default(false);
            $table->string('stale_reason', 200)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['audience', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE guide_entries ADD CONSTRAINT guide_entries_enum_check CHECK (audience IN ('petugas','anggota') AND source IN ('auto','manual'))");
            DB::statement("ALTER TABLE guide_entries ADD CONSTRAINT guide_entries_steps_check CHECK (json_typeof(steps) = 'array')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_entries');
    }
};
