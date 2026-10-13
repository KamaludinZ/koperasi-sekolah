<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penerimaan barang dari pesanan pembelian: satu catatan per kedatangan (nomor TB-YYYYMM-NNNN,
     * tanggal terima, surat jalan, catatan, petugas, jumlah & nilai) dan barang yang diterima, masing-
     * masing tertaut ke item pesanan dan mutasi stok yang menambah stok barang.
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('received_at');
            $table->string('delivery_note', 50)->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedInteger('total_quantity')->default(0);
            $table->bigInteger('total_value')->default(0);
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'received_at']);
            $table->index(['supplier_id', 'received_at']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->bigInteger('cost');
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('purchase_order_item_id');
            $table->index('product_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_qty_check CHECK (quantity > 0 AND cost >= 0)');
            DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_totals_check CHECK (total_quantity >= 0 AND total_value >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
