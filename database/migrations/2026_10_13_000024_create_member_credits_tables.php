<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bon (piutang) anggota & pelunasannya.
     * - member_credits: satu bon per transaksi kasir bermetode `bon` (nilai, jatuh tempo, terbayar, status
     *   belum → sebagian → lunas, atau dibatalkan bila transaksinya dibatalkan).
     * - member_credit_payments: pelunasan tunai atau potong tabungan (tautan ke mutasi tabungan).
     * - member_credit_allocations: pembagian satu pelunasan ke bon-bon (jatuh tempo terlama dulu).
     * Tambahan: batas bon per anggota (null = ikut pengaturan jenis anggota) dan asal mutasi tabungan
     * (transaksi kasir / pelunasan bon / retur) untuk penelusuran.
     */
    public function up(): void
    {
        Schema::create('member_credits', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->bigInteger('paid')->default(0);
            $table->date('due_at');
            $table->string('status', 12)->default('belum');
            $table->string('note', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_off_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['status', 'due_at']);
            $table->index('created_at');
        });

        Schema::create('member_credit_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('method', 10);                   // tunai | tabungan
            $table->foreignId('savings_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('note', 150)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['member_id', 'paid_at']);
            $table->index('paid_at');
        });

        Schema::create('member_credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_credit_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_credit_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');

            $table->unique(['member_credit_payment_id', 'member_credit_id'], 'credit_alloc_unique');
            $table->index('member_credit_id');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->bigInteger('credit_limit')->nullable()->after('balance');
        });

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->string('source_type', 20)->nullable()->after('note'); // transaction | credit_payment | return
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE member_credits ADD CONSTRAINT member_credits_status_check CHECK (status IN ('belum','sebagian','lunas','dibatalkan'))");
            DB::statement('ALTER TABLE member_credits ADD CONSTRAINT member_credits_paid_check CHECK (amount > 0 AND paid >= 0 AND paid <= amount)');
            DB::statement("ALTER TABLE member_credit_payments ADD CONSTRAINT member_credit_payments_check CHECK (amount > 0 AND method IN ('tunai','tabungan'))");
            DB::statement('ALTER TABLE member_credit_allocations ADD CONSTRAINT member_credit_allocations_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE members ADD CONSTRAINT members_credit_limit_check CHECK (credit_limit IS NULL OR credit_limit >= 0)');
            DB::statement("ALTER TABLE savings_transactions ADD CONSTRAINT savings_source_valid CHECK (source_type IS NULL OR source_type IN ('transaction','credit_payment','return'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE members DROP CONSTRAINT IF EXISTS members_credit_limit_check');
            DB::statement('ALTER TABLE savings_transactions DROP CONSTRAINT IF EXISTS savings_source_valid');
        }
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('credit_limit'));
        Schema::dropIfExists('member_credit_allocations');
        Schema::dropIfExists('member_credit_payments');
        Schema::dropIfExists('member_credits');
    }
};
