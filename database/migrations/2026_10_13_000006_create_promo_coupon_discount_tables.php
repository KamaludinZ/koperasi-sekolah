<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diskon & promo:
     * - promos: promo otomatis (persen, potongan rupiah per barang, beli X gratis Y, harga grosir)
     *   dengan periode, hari & jam berlaku, minimal belanja, dan saklar aktif;
     * - promo_targets: cakupan promo (kategori atau barang tertentu; kosong = semua barang);
     * - coupons: kode kupon/voucher dengan kuota, batas per anggota, dan masa berlaku;
     * - transaction_discounts: rincian potongan tiap transaksi (manual / promo / kupon)
     *   beserta alasan — sekaligus catatan pemakaian kupon & dampak promo.
     */
    public function up(): void
    {
        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 15);                 // persen | nominal | beli_gratis | grosir
            $table->decimal('value', 14, 2)->default(0); // persen, rupiah per barang, atau harga grosir
            $table->unsignedSmallInteger('buy_qty')->nullable();
            $table->unsignedSmallInteger('free_qty')->nullable();
            $table->unsignedSmallInteger('min_qty')->default(1);
            $table->decimal('min_purchase', 14, 2)->default(0);
            $table->string('scope', 10)->default('semua'); // semua | kategori | barang
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('days');                       // hari ISO 1–7 yang berlaku
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_on', 'ends_on']);
        });

        Schema::create('promo_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unique(['promo_id', 'category_id', 'product_id']);
            $table->index('category_id');
            $table->index('product_id');
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();       // huruf besar tanpa spasi
            $table->string('name', 100);
            $table->string('type', 10);                 // persen | nominal
            $table->decimal('value', 14, 2);
            $table->decimal('max_discount', 14, 2)->nullable();
            $table->decimal('min_purchase', 14, 2)->default(0);
            $table->unsignedInteger('quota')->nullable();       // null = tanpa batas
            $table->unsignedSmallInteger('per_member')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_on', 'ends_on']);
        });

        Schema::create('transaction_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->string('source', 10);               // manual | promo | kupon
            $table->foreignId('promo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label', 120);               // snapshot nama promo / kode kupon / "Diskon manual 10%"
            $table->decimal('amount', 14, 2);
            $table->string('reason', 150)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('transaction_id');
            $table->index(['promo_id', 'created_at']);
            $table->index(['coupon_id', 'created_at']);
            $table->index(['source', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE promos ADD CONSTRAINT promos_type_valid CHECK (type IN ('persen', 'nominal', 'beli_gratis', 'grosir'))");
            DB::statement("ALTER TABLE promos ADD CONSTRAINT promos_scope_valid CHECK (scope IN ('semua', 'kategori', 'barang'))");
            DB::statement('ALTER TABLE promos ADD CONSTRAINT promos_period_valid CHECK (ends_on >= starts_on)');
            DB::statement("ALTER TABLE promos ADD CONSTRAINT promos_value_valid CHECK (
                value >= 0 AND min_purchase >= 0 AND min_qty >= 1
                AND (type <> 'persen' OR (value > 0 AND value <= 100))
                AND (type <> 'beli_gratis' OR (buy_qty IS NOT NULL AND free_qty IS NOT NULL AND buy_qty >= 1 AND free_qty >= 1))
                AND (type NOT IN ('nominal', 'grosir') OR value > 0)
            )");
            DB::statement('ALTER TABLE promos ADD CONSTRAINT promos_time_valid CHECK ((time_from IS NULL) = (time_to IS NULL) AND (time_from IS NULL OR time_to > time_from))');
            DB::statement('ALTER TABLE promo_targets ADD CONSTRAINT promo_targets_one_target CHECK ((category_id IS NULL) <> (product_id IS NULL))');
            DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_valid CHECK (type IN ('persen', 'nominal'))");
            DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value_valid CHECK (value > 0 AND (type <> 'persen' OR value <= 100) AND min_purchase >= 0 AND (max_discount IS NULL OR max_discount > 0))");
            DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_period_valid CHECK (ends_on >= starts_on)');
            DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_code_format CHECK (code = UPPER(code) AND code !~ '\\s')");
            DB::statement("ALTER TABLE transaction_discounts ADD CONSTRAINT transaction_discounts_source_valid CHECK (source IN ('manual', 'promo', 'kupon'))");
            DB::statement('ALTER TABLE transaction_discounts ADD CONSTRAINT transaction_discounts_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE transaction_discounts ADD CONSTRAINT transaction_discounts_ref_valid CHECK (
                (source = 'manual' AND promo_id IS NULL AND coupon_id IS NULL)
                OR (source = 'promo' AND coupon_id IS NULL)
                OR (source = 'kupon' AND promo_id IS NULL AND coupon_id IS NOT NULL)
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_discounts');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('promo_targets');
        Schema::dropIfExists('promos');
    }
};
