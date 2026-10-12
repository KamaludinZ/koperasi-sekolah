<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mutasi tabungan anggota (setor/tarik) beserta saldo sesudahnya.
     * Riwayat tidak ikut terhapus: anggota yang punya mutasi tidak bisa dihapus.
     */
    public function up(): void
    {
        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // setor | tarik
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // petugas
            $table->timestamp('created_at')->nullable();

            $table->index(['member_id', 'created_at']);
            $table->index('created_at');
            $table->index('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE savings_transactions ADD CONSTRAINT savings_type_valid CHECK (type IN ('setor', 'tarik'))");
            DB::statement('ALTER TABLE savings_transactions ADD CONSTRAINT savings_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE savings_transactions ADD CONSTRAINT savings_balance_non_negative CHECK (balance_before >= 0 AND balance_after >= 0)');
            DB::statement("ALTER TABLE savings_transactions ADD CONSTRAINT savings_balance_consistent CHECK (
                balance_after = balance_before + CASE WHEN type = 'setor' THEN amount ELSE -amount END
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
    }
};
