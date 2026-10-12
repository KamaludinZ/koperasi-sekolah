<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PostgreSQL tidak membuat index otomatis untuk kolom foreign key.
        Schema::table('products', function (Blueprint $table) {
            $table->index('category_id');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['product_id', 'created_at']);
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->index('transaction_id');
            $table->index('product_id');
        });

        // Jaga data barang tetap masuk akal di level database.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_non_negative CHECK (stock >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_min_stock_non_negative CHECK (min_stock >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_non_negative CHECK (price >= 0 AND cost_price >= 0)');
        }

        // Pengaturan koperasi (key-value), mis. batas minimum stok bawaan.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_price_non_negative');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_min_stock_non_negative');
            DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_stock_non_negative');
        }

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
            $table->dropIndex(['transaction_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
        });
    }
};
