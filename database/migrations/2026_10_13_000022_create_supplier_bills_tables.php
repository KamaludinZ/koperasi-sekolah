<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hutang ke supplier: satu tagihan per penerimaan barang (nilai barang yang datang, jatuh tempo =
     * tanggal terima + tempo supplier) dan pembayaran-pembayarannya (tunai/transfer, bukti, catatan).
     * `paid` adalah jumlah pembayaran; status belum → sebagian → lunas.
     */
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->bigInteger('paid')->default(0);
            $table->date('due_at');
            $table->string('status', 10)->default('belum');
            $table->timestamp('paid_off_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->date('paid_at');
            $table->string('method', 10);                  // tunai | transfer
            $table->string('reference', 50)->nullable();   // no. bukti transfer
            $table->string('note', 200)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supplier_bill_id', 'paid_at']);
            $table->index('paid_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_status_check CHECK (status IN ('belum','sebagian','lunas'))");
            DB::statement('ALTER TABLE supplier_bills ADD CONSTRAINT supplier_bills_paid_check CHECK (amount >= 0 AND paid >= 0 AND paid <= amount)');
            DB::statement("ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_check CHECK (amount > 0 AND method IN ('tunai','transfer'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bills');
    }
};
