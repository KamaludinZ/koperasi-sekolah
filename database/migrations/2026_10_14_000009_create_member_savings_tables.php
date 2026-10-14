<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo & mutasi simpanan per jenis (terpisah dari tabungan `members.balance` / savings_transactions).
     * - member_saving_accounts: satu saldo per anggota per jenis (dikunci saat mutasi).
     * - member_saving_entries: buku mutasi setor | tarik | potong (bayar belanja) dengan saldo sebelum/sesudah,
     *   `period` (YYYY-MM) untuk setoran simpanan wajib bulanan, asal mutasi, dan kunci idempoten.
     */
    public function up(): void
    {
        Schema::create('member_saving_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('saving_type_id')->constrained()->restrictOnDelete();
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['member_id', 'saving_type_id']);
            $table->index('saving_type_id');
        });

        Schema::create('member_saving_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('saving_type_id')->constrained()->restrictOnDelete();
            $table->string('type', 10);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->char('period', 7)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('source_type', 20)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('client_ref', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'saving_type_id', 'created_at']);
            $table->index(['saving_type_id', 'period']);
            $table->index('created_at');
            $table->index(['source_type', 'source_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE member_saving_accounts ADD CONSTRAINT member_saving_balance_positive CHECK (balance >= 0)');
            DB::statement("ALTER TABLE member_saving_entries ADD CONSTRAINT member_saving_entry_type_valid CHECK (type IN ('setor','tarik','potong'))");
            DB::statement('ALTER TABLE member_saving_entries ADD CONSTRAINT member_saving_entry_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE member_saving_entries ADD CONSTRAINT member_saving_entry_consistent CHECK (balance_after >= 0 AND balance_after = CASE WHEN type = 'setor' THEN balance_before + amount ELSE balance_before - amount END)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_saving_entries');
        Schema::dropIfExists('member_saving_accounts');
    }
};
