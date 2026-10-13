<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retur barang & pembatalan (void) transaksi kasir.
     * - transaction_returns: pengajuan RTR-/BTL- (jenis retur|batal) dengan alasan, kondisi barang (kembali
     *   ke stok atau tidak), cara pengembalian dana, nilai, status menunggu → disetujui/ditolak, pengaju &
     *   pemutus. Satu transaksi hanya boleh punya satu pengajuan menunggu, dan paling banyak satu
     *   pembatalan yang disetujui (indeks unik parsial).
     * - transaction_return_items: barang yang dikembalikan per baris transaksi (jumlah, harga bersih, nilai).
     * - transactions: voided_at/voided_by/void_reason (transaksi dibatalkan, tetap tersimpan) dan
     *   returned_amount (jumlah retur disetujui) untuk laporan.
     */
    public function up(): void
    {
        Schema::create('transaction_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('kind', 10);                         // retur | batal
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('menunggu'); // menunggu | disetujui | ditolak
            $table->string('reason_key', 20);
            $table->string('reason', 100);
            $table->string('note', 200)->nullable();
            $table->boolean('restock')->default(true);           // barang kembali ke stok
            $table->string('refund_method', 10);                 // tunai | qris | tabungan | bon
            $table->bigInteger('amount');
            $table->unsignedInteger('quantity');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index(['transaction_id', 'status']);
            $table->index('requested_by');
            $table->index('decided_at');
        });

        Schema::create('transaction_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price');                   // harga bersih setelah diskon transaksi
            $table->bigInteger('amount');

            $table->unique(['transaction_return_id', 'transaction_item_id'], 'return_item_unique');
            $table->index('transaction_item_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('item_count');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable()->after('voided_by');
            $table->bigInteger('returned_amount')->default(0)->after('void_reason');
            $table->index('voided_at');
        });

        // Satu pengajuan menunggu per transaksi; paling banyak satu pembatalan disetujui per transaksi.
        DB::statement("CREATE UNIQUE INDEX transaction_returns_one_pending ON transaction_returns (transaction_id) WHERE status = 'menunggu'");
        DB::statement("CREATE UNIQUE INDEX transaction_returns_one_void ON transaction_returns (transaction_id) WHERE kind = 'batal' AND status = 'disetujui'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE transaction_returns ADD CONSTRAINT transaction_returns_kind_check CHECK (kind IN ('retur','batal'))");
            DB::statement("ALTER TABLE transaction_returns ADD CONSTRAINT transaction_returns_status_check CHECK (status IN ('menunggu','disetujui','ditolak'))");
            DB::statement("ALTER TABLE transaction_returns ADD CONSTRAINT transaction_returns_refund_check CHECK (refund_method IN ('tunai','qris','tabungan','bon'))");
            DB::statement('ALTER TABLE transaction_returns ADD CONSTRAINT transaction_returns_amount_check CHECK (amount >= 0 AND quantity > 0)');
            DB::statement("ALTER TABLE transaction_returns ADD CONSTRAINT transaction_returns_decided_check CHECK ((status = 'menunggu') = (decided_at IS NULL))");
            DB::statement('ALTER TABLE transaction_return_items ADD CONSTRAINT transaction_return_items_check CHECK (quantity > 0 AND unit_price >= 0 AND amount >= 0)');
            DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_returned_check CHECK (returned_amount >= 0 AND returned_amount <= total)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_returned_check');
        }
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['voided_at']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason', 'returned_amount']);
        });
        Schema::dropIfExists('transaction_return_items');
        Schema::dropIfExists('transaction_returns');
    }
};
