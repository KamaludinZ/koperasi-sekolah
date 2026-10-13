<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pesanan pembelian ke supplier dan barang-barangnya. Nomor PO-YYYYMM-NNNN unik; status
     * draf → dipesan → sebagian → diterima, atau dibatalkan (dengan alasan). Item menyimpan salinan
     * kode/nama/satuan barang dan harga beli saat dipesan, jumlah dipesan & sudah diterima.
     */
    public const STATUSES = ['draf', 'dipesan', 'sebagian', 'diterima', 'dibatalkan'];

    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('draf');
            $table->date('ordered_at');
            $table->date('expected_at')->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedSmallInteger('terms_days')->default(0);      // salinan syarat bayar supplier saat dipesan
            $table->bigInteger('total')->default(0);                       // Σ qty × harga beli
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();                      // ditandai dipesan
            $table->timestamp('received_at')->nullable();                  // lengkap diterima
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['status', 'expected_at']);
            $table->index(['supplier_id', 'ordered_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_code', 50);
            $table->string('product_name', 150);
            $table->string('unit', 20)->default('pcs');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->bigInteger('cost');                                    // harga beli per satuan
            $table->timestamps();

            $table->unique(['purchase_order_id', 'product_id']);
            $table->index('product_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('".implode("','", self::STATUSES)."'))");
            DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_total_check CHECK (total >= 0)');
            DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_qty_check CHECK (quantity > 0 AND received_quantity <= quantity)');
            DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_cost_check CHECK (cost >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
