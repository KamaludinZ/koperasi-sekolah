<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pajak & pembulatan pesanan portal mengikuti aturan kasir (PriceCalculator): total = subtotal − diskon + pajak + pembulatan. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('tax', 14, 2)->default(0)->after('discount');
            $table->decimal('tax_included', 14, 2)->default(0)->after('tax');
            $table->decimal('rounding', 14, 2)->default(0)->after('tax_included');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_total_consistent');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_consistent CHECK (total = subtotal - discount + tax + rounding AND total >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_total_consistent');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_consistent CHECK (total = subtotal - discount AND total >= 0)');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tax', 'tax_included', 'rounding']);
        });
    }
};
