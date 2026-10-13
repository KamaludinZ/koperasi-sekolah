<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier (pemasok barang): kontak, alamat, syarat pembayaran (0 = tunai, N = tempo N hari
     * setelah barang diterima), catatan, status aktif, dan hapus lunak beserta alasannya; serta
     * kategori barang yang dipasok (untuk saran supplier saat stok menipis).
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('contact', 60)->nullable();
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->string('address', 200)->nullable();
            $table->unsignedSmallInteger('terms_days')->default(0);
            $table->string('note', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delete_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'name']);
            $table->index('deleted_at');
        });
        DB::statement('CREATE UNIQUE INDEX suppliers_name_active_unique ON suppliers (LOWER(name)) WHERE deleted_at IS NULL');

        Schema::create('category_supplier', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['supplier_id', 'category_id']);
            $table->index('category_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_terms_check CHECK (terms_days <= 120)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_supplier');
        Schema::dropIfExists('suppliers');
    }
};
