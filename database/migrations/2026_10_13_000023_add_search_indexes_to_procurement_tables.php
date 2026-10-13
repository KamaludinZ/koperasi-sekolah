<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index untuk pencarian & urutan di halaman Pengadaan: cari supplier menurut telepon, daftar
     * pesanan terbaru & per pembuat, cari barang dalam pesanan menurut kode, riwayat penerimaan
     * terbaru, dan riwayat impor menurut nama berkas.
     */
    public function up(): void
    {
        Schema::table('suppliers', fn (Blueprint $t) => $t->index('phone'));
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->index(['ordered_at', 'id']);
            $t->index('created_by');
        });
        Schema::table('purchase_order_items', fn (Blueprint $t) => $t->index('product_code'));
        Schema::table('goods_receipts', fn (Blueprint $t) => $t->index(['received_at', 'id']));
        Schema::table('product_imports', fn (Blueprint $t) => $t->index('file_name'));
    }

    public function down(): void
    {
        Schema::table('product_imports', fn (Blueprint $t) => $t->dropIndex(['file_name']));
        Schema::table('goods_receipts', fn (Blueprint $t) => $t->dropIndex(['received_at', 'id']));
        Schema::table('purchase_order_items', fn (Blueprint $t) => $t->dropIndex(['product_code']));
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropIndex(['ordered_at', 'id']);
            $t->dropIndex(['created_by']);
        });
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropIndex(['phone']));
    }
};
