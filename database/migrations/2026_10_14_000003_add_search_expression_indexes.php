<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Indeks untuk pola pencarian aplikasi:
     * - LOWER(code) pada barang: pencocokan kode persis saat scan barcode di kasir (SQLite & PostgreSQL).
     * - PostgreSQL: pg_trgm + indeks GIN trigram untuk pencarian sebagian `LOWER(kolom) LIKE '%kata%'`
     *   (nama/kode barang, nama/kode anggota, nama supplier, nomor invoice, label audit). Bila ekstensi
     *   tidak bisa dipasang (hak akses), indeks trigram dilewati tanpa menggagalkan migrasi.
     */
    private const TRIGRAM = [
        'products_name_trgm' => ['products', 'LOWER(name)'],
        'products_code_trgm' => ['products', 'LOWER(code)'],
        'members_name_trgm' => ['members', 'LOWER(name)'],
        'members_code_trgm' => ['members', 'LOWER(member_code)'],
        'suppliers_name_trgm' => ['suppliers', 'LOWER(name)'],
        'transactions_invoice_trgm' => ['transactions', 'UPPER(invoice_number)'],
        'activity_logs_label_trgm' => ['activity_logs', 'LOWER(subject_label)'],
    ];

    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS products_code_lower_index ON products (LOWER(code))');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable) {
            return; // tanpa pg_trgm: pencarian tetap jalan, hanya tanpa percepatan trigram
        }
        foreach (self::TRIGRAM as $name => [$table, $expr]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} USING gin ({$expr} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_code_lower_index');
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_keys(self::TRIGRAM) as $name) {
                DB::statement("DROP INDEX IF EXISTS {$name}");
            }
        }
    }
};
