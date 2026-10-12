<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot pajak & pembulatan saat transaksi dibuat (pengaturan bisa berubah kemudian):
     * - tax_label, tax_rate: nama & tarif pajak yang berlaku (0 = tidak dipungut);
     * - tax_included: rincian pajak yang sudah termasuk harga (tidak menambah total);
     * - rounding: selisih pembulatan (bisa negatif). total = subtotal - discount + tax + rounding.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('tax_label', 20)->nullable()->after('tax');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('tax_label');
            $table->decimal('tax_included', 12, 2)->default(0)->after('tax_rate');
            $table->decimal('rounding', 12, 2)->default(0)->after('discount');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_tax_rate_range CHECK (tax_rate >= 0 AND tax_rate <= 100)');
            DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_total_consistent CHECK (total = subtotal - discount + tax + rounding)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_total_consistent');
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_tax_rate_range');
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['tax_label', 'tax_rate', 'tax_included', 'rounding']);
        });
    }
};
