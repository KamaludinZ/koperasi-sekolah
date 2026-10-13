<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shift kasir & kas laci: shift dibuka dengan modal awal dan ditutup dengan hitungan uang
     * (kas seharusnya, uang dihitung, selisih disimpan sebagai snapshot saat tutup); setoran/
     * penarikan kas laci di luar penjualan; transaksi penjualan ditautkan ke shift-nya.
     * Hanya boleh ada satu shift terbuka (satu laci kas koperasi).
     */
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30);                     // mis. Shift pagi
            $table->string('status', 10)->default('open');  // open | closed
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // petugas yang membuka
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 14, 2);
            $table->decimal('expected_cash', 14, 2)->nullable(); // snapshot saat tutup
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();    // dihitung − seharusnya
            $table->string('close_note', 200)->nullable();
            $table->timestamps();

            $table->index('opened_at');
            $table->index(['user_id', 'opened_at']);
            $table->index(['status', 'opened_at']);
        });

        // Satu shift terbuka saja (indeks unik parsial; didukung SQLite & PostgreSQL).
        DB::statement("CREATE UNIQUE INDEX cash_shifts_single_open ON cash_shifts (status) WHERE status = 'open'");

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_shift_id')->constrained()->restrictOnDelete();
            $table->string('type', 5); // in | out
            $table->decimal('amount', 14, 2);
            $table->string('reason', 150);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['cash_shift_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('cash_shift_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_status_valid CHECK (status IN ('open', 'closed'))");
            DB::statement('ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_cash_non_negative CHECK (opening_cash >= 0 AND (counted_cash IS NULL OR counted_cash >= 0))');
            DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_close_consistent CHECK (
                (status = 'open' AND closed_at IS NULL AND counted_cash IS NULL AND expected_cash IS NULL AND difference IS NULL)
                OR (status = 'closed' AND closed_at IS NOT NULL AND counted_cash IS NOT NULL AND expected_cash IS NOT NULL
                    AND difference = counted_cash - expected_cash AND closed_at >= opened_at)
            )");
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type_valid CHECK (type IN ('in', 'out'))");
            DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_shift_id');
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_shifts');
    }
};
