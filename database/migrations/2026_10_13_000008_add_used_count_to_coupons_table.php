<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penghitung pemakaian kupon: dinaikkan secara atomik saat kupon dipakai (sisa kuota =
     * quota − used_count) dan dikembalikan saat pemakaian dibatalkan. Diisi dari riwayat
     * pemakaian yang sudah ada. PostgreSQL menolak pemakaian melebihi kuota.
     */
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('used_count')->default(0)->after('per_member');
        });

        DB::table('coupons')->update([
            'used_count' => DB::raw('(SELECT COUNT(*) FROM transaction_discounts WHERE transaction_discounts.coupon_id = coupons.id)'),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_used_within_quota CHECK (used_count >= 0 AND (quota IS NULL OR used_count <= quota))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE coupons DROP CONSTRAINT IF EXISTS coupons_used_within_quota');
        }
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('used_count');
        });
    }
};
