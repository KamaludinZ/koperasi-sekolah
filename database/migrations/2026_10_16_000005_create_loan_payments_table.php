<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pembayaran angsuran pinjaman (input manual pengurus): satu baris per uang yang benar-benar diterima,
     * dengan rincian alokasi ke angsuran (allocations: [{installment_id, sequence, amount}]) agar bisa dikoreksi
     * dan ditelusuri. Pembayaran yang dikoreksi tidak dihapus: ditandai voided_at/voided_by/void_reason.
     */
    public function up(): void
    {
        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->date('paid_at');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 15);
            $table->string('note', 200)->nullable();
            $table->json('allocations');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->foreignId('replaces_id')->nullable()->constrained('loan_payments')->nullOnDelete();
            $table->timestamps();
            $table->index(['loan_id', 'paid_at']);
            $table->index('paid_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loan_payments ADD CONSTRAINT loan_payments_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE loan_payments ADD CONSTRAINT loan_payments_method_valid CHECK (payment_method IN ('potong_gaji','transfer','tunai'))");
            DB::statement('ALTER TABLE loan_payments ADD CONSTRAINT loan_payments_void_consistent CHECK ((voided_at IS NULL) = (void_reason IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
    }
};
