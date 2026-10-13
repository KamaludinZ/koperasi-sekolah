<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat impor yang lebih lengkap: siapa yang menyimpan impor (bisa berbeda dari pengunggah),
     * catatan peringatan saat berkas dibaca (mis. kolom tak dikenal yang diabaikan), dan indeks
     * untuk menelusuri riwayat per petugas & per status.
     */
    public function up(): void
    {
        Schema::table('product_imports', function (Blueprint $table) {
            $table->foreignId('committed_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->json('warnings')->nullable()->after('file_error');
            $table->index(['committed_by', 'finished_at']);
        });
        Schema::table('product_import_rows', function (Blueprint $table) {
            $table->index(['product_import_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::table('product_import_rows', function (Blueprint $table) {
            $table->dropIndex(['product_import_id', 'result']);
        });
        Schema::table('product_imports', function (Blueprint $table) {
            $table->dropIndex(['committed_by', 'finished_at']);
            $table->dropConstrainedForeignId('committed_by');
            $table->dropColumn('warnings');
        });
    }
};
