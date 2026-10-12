<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jejak audit seluruh aktivitas petugas: login/keluar, penjualan, stok, barang,
     * anggota, tabungan, pengguna, dan unduhan laporan. Hanya boleh ditambah:
     * di PostgreSQL, UPDATE/DELETE ditolak trigger agar riwayat tidak bisa diubah.
     * Nama & peran pelaku disimpan sebagai snapshot (tetap terbaca walau akun berubah).
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);              // mis. auth.login, sale.created, savings.withdraw
            $table->string('category', 20);           // auth, transaksi, tabungan, stok, barang, anggota, pengguna, laporan
            $table->string('description', 255);       // kalimat yang dibaca manusia
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 150)->nullable(); // mis. INV-20261012-0001, Andi Pratama
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->string('actor_role', 20)->nullable();
            $table->bigInteger('amount')->nullable(); // nominal rupiah bila ada (penjualan, setor, tarik)
            $table->json('properties')->nullable();   // rincian & perubahan sebelum/sesudah
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'id']);
            $table->index(['category', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('event');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION activity_logs_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'activity_logs hanya boleh ditambah (tidak bisa diubah/dihapus)';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER activity_logs_no_update_delete
                    BEFORE UPDATE OR DELETE ON activity_logs
                    FOR EACH ROW EXECUTE FUNCTION activity_logs_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS activity_logs_no_update_delete ON activity_logs; DROP FUNCTION IF EXISTS activity_logs_immutable();');
        }

        Schema::dropIfExists('activity_logs');
    }
};
