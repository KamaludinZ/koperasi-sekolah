<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Impor barang dari berkas CSV/XLSX: satu baris per unggahan (berkas di disk privat, cara
     * menyimpan, status, dan hasil hitungan) dan baris-baris berkas yang terbaca (nilai mentah,
     * tindakan, kesalahan, dilewati) untuk pratinjau, perbaikan, dan riwayat.
     */
    public const STATUSES = ['diunggah', 'pratinjau', 'diproses', 'selesai', 'sebagian', 'gagal'];

    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name', 200);
            $table->string('format', 10);                  // csv | xlsx
            $table->string('file_path', 255)->nullable();  // di disk privat; dihapus saat dibersihkan
            $table->unsignedInteger('file_size')->default(0);
            $table->string('status', 12)->default('diunggah');
            $table->string('mode', 10)->default('semua'); // semua | tambah | perbarui
            $table->boolean('update_stock')->default(false);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('file_error', 300)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('product_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line');                // nomor baris di berkas (judul = 1)
            $table->json('values');                         // kolom => teks apa adanya / hasil perbaikan
            $table->boolean('skipped')->default(false);     // dilewati petugas
            $table->string('result', 12)->nullable();       // setelah disimpan: baru | diperbarui | dilewati | gagal
            $table->string('message', 300)->nullable();     // alasan dilewati/gagal
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_import_id', 'line']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_status_check CHECK (status IN ('".implode("','", self::STATUSES)."'))");
            DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_mode_check CHECK (mode IN ('semua','tambah','perbarui'))");
            DB::statement("ALTER TABLE product_imports ADD CONSTRAINT product_imports_format_check CHECK (format IN ('csv','xlsx'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_rows');
        Schema::dropIfExists('product_imports');
    }
};
