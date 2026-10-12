<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot harga modal per item saat terjual, agar laba kotor laporan
     * tidak berubah ketika harga modal barang diperbarui.
     */
    public function up(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->decimal('cost_price', 14, 2)->default(0)->after('price');
        });

        // Item lama: pakai harga modal barang saat ini (perkiraan terbaik).
        DB::table('transaction_items')->update([
            'cost_price' => DB::raw('COALESCE((SELECT products.cost_price FROM products WHERE products.id = transaction_items.product_id), 0)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
