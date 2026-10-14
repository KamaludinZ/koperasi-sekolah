<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nilai barcode barang (opsional). Kosong = barcode mengikuti kode barang (CODE128 dari `code`).
     * Unik tanpa membedakan huruf besar/kecil (indeks LOWER(barcode)) agar hasil scan selalu menunjuk satu barang.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable()->after('code');
        });
        DB::statement('CREATE UNIQUE INDEX products_barcode_lower_unique ON products (LOWER(barcode))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_barcode_lower_unique');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }
};
