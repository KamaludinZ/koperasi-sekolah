<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kategori transaksi kas harian (uang masuk/keluar laci di luar penjualan) untuk rekap:
     * kembalian | bendahara | operasional | lainnya.
     */
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('category', 20)->default('lainnya')->after('type');
            $table->index(['category', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_category_valid CHECK (category IN ('kembalian', 'bendahara', 'operasional', 'lainnya'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cash_movements DROP CONSTRAINT IF EXISTS cash_movements_category_valid');
        }
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex(['category', 'created_at']);
            $table->dropColumn('category');
        });
    }
};
