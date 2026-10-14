<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pesanan checkout mandiri dari portal anggota.
     * - status: menunggu | diproses | siap | selesai | dibatalkan (riwayat di order_status_logs)
     * - fulfillment: ambil (di koperasi) | antar (ke kelas/ruang, `delivery_to`)
     * - payment_method: tabungan | simpanan (saving_type_id) | tunai | qris; payment_status: belum | lunas | dikembalikan
     * Stok dicadangkan (dikurangi) saat pesanan dibuat; saldo dipotong saat itu bila bayar potong saldo.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('status', 15)->default('menunggu');
            $table->string('fulfillment', 10);
            $table->string('delivery_to', 100)->nullable();
            $table->string('payment_method', 15);
            $table->foreignId('saving_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_status', 15)->default('belum');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->unsignedInteger('item_count');
            $table->string('note', 200)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_ref', 64)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->unsignedInteger('quantity');
            $table->decimal('price', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->index('product_id');
        });

        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 15)->nullable();
            $table->string('to_status', 15);
            $table->string('note', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name', 100);
            $table->timestamp('created_at');
            $table->index(['order_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('menunggu','diproses','siap','selesai','dibatalkan'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_valid CHECK (fulfillment IN ('ambil','antar'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_valid CHECK (payment_method IN ('tabungan','simpanan','tunai','qris'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_valid CHECK (payment_status IN ('belum','lunas','dikembalikan'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_consistent CHECK (total = subtotal - discount AND total >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_positive CHECK (quantity > 0 AND price >= 0 AND subtotal = price * quantity)');
            // Potong saldo tabungan untuk pesanan portal.
            DB::statement('ALTER TABLE savings_transactions DROP CONSTRAINT IF EXISTS savings_source_valid');
            DB::statement("ALTER TABLE savings_transactions ADD CONSTRAINT savings_source_valid CHECK (source_type IS NULL OR source_type IN ('transaction','credit_payment','return','order'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE savings_transactions DROP CONSTRAINT IF EXISTS savings_source_valid');
            DB::statement("ALTER TABLE savings_transactions ADD CONSTRAINT savings_source_valid CHECK (source_type IS NULL OR source_type IN ('transaction','credit_payment','return'))");
        }
        Schema::dropIfExists('order_status_logs');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
