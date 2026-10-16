<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kasir offline (Plan B — offline-first):
     * - pos_offline_catalogs: salinan katalog yang diunduh perangkat kasir untuk dipakai tanpa internet — harga per
     *   barang & pengaturan pajak saat diunduh, milik petugas & perangkat tertentu, berlaku terbatas. Saat sinkron,
     *   harga setiap baris penjualan offline dicocokkan dengan salinan ini (harga yang diubah di perangkat ditolak);
     * - offline_sales: kotak masuk setiap penjualan offline yang dikirim perangkat (idempoten lewat client_ref):
     *   isi mentah, status diterima | perlu_dicek | ditolak, tanda masalah (stok kurang, tanpa shift, barang tiada),
     *   transaksi yang dibuat, dan tinjauan admin;
     * - transactions.source (kasir | offline) & synced_at: asal transaksi dan kapan penjualan offline masuk server.
     */
    public function up(): void
    {
        Schema::create('pos_offline_catalogs', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64);
            $table->json('prices');                // {product_id: harga}
            $table->json('tax');                   // pengaturan pajak & pembulatan saat diunduh
            $table->unsignedInteger('product_count');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');

            $table->index(['user_id', 'issued_at']);
        });

        Schema::create('offline_sales', function (Blueprint $table) {
            $table->id();
            $table->string('client_ref', 64)->unique();
            $table->string('catalog_id', 26)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('device_id', 64);
            $table->string('local_number', 40);
            $table->timestamp('sold_at');
            $table->json('payload');
            $table->bigInteger('total');
            $table->string('payment_method', 10);
            $table->string('status', 12);          // diterima | perlu_dicek | ditolak
            $table->json('flags')->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['user_id', 'sold_at']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('source', 10)->default('kasir');
            $table->timestamp('synced_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE offline_sales ADD CONSTRAINT offline_sales_status_check CHECK (status IN ('diterima','perlu_dicek','ditolak') AND payment_method IN ('tunai','qris') AND total >= 0)");
            DB::statement("ALTER TABLE offline_sales ADD CONSTRAINT offline_sales_transaction_check CHECK ((status = 'ditolak') = (transaction_id IS NULL) OR status = 'ditolak')");
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_source_check CHECK (source IN ('kasir','offline') AND (source = 'kasir' OR synced_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_source_check');
        }
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['source', 'synced_at']);
        });
        Schema::dropIfExists('offline_sales');
        Schema::dropIfExists('pos_offline_catalogs');
    }
};
