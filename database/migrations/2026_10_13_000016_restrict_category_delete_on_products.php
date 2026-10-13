<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kategori yang masih dipakai barang tidak boleh terhapus permanen: kunci tamu
     * products.category_id diubah dari "kosongkan saat dihapus" menjadi "tolak".
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });
        $this->restorePartialUnique();
    }

    /** SQLite membangun ulang tabel saat kunci tamu diubah; indeks unik parsial kode barang dipasang lagi. */
    private function restorePartialUnique(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS products_code_active_unique');
            DB::statement('CREATE UNIQUE INDEX products_code_active_unique ON products (code) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });
        $this->restorePartialUnique();
    }
};
