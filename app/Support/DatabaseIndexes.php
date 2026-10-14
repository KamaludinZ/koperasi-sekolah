<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pemeriksaan indeks pada kolom yang sering dicari/diagregasi, dibaca langsung dari skema basis data
 * (Schema::getIndexes). Indeks dianggap ada bila kolom yang diharapkan menjadi kolom pertama sebuah indeks.
 */
class DatabaseIndexes
{
    /** tabel → [kolom pertama indeks → kegunaan] */
    public const EXPECTED = [
        'transactions' => ['created_at' => 'Riwayat & laporan per tanggal', 'invoice_number' => 'Cari nomor transaksi', 'payment_method' => 'Saring metode bayar', 'member_id' => 'Belanja anggota', 'voided_at' => 'Abaikan transaksi batal'],
        'transaction_items' => ['transaction_id' => 'Rincian transaksi', 'product_id' => 'Barang terlaris'],
        'products' => ['code' => 'Cari kode barang / barcode', 'name' => 'Cari nama barang', 'category_id' => 'Saring kategori'],
        'members' => ['member_code' => 'Cari kode anggota', 'name' => 'Cari nama anggota'],
        'savings_transactions' => ['member_id' => 'Mutasi tabungan anggota', 'created_at' => 'Mutasi per tanggal'],
        'stock_movements' => ['product_id' => 'Riwayat stok barang', 'created_at' => 'Mutasi stok per tanggal'],
        'activity_logs' => ['created_at' => 'Jejak audit per tanggal', 'category' => 'Saring kategori audit', 'subject_type' => 'Riwayat objek'],
        'member_credits' => ['member_id' => 'Bon per anggota', 'status' => 'Bon belum lunas'],
        'transaction_returns' => ['status' => 'Antrean persetujuan', 'transaction_id' => 'Retur per transaksi'],
        'member_saving_entries' => ['member_id' => 'Mutasi & bulan terbayar per anggota', 'saving_type_id' => 'Rekap per jenis simpanan', 'created_at' => 'Mutasi simpanan per tanggal'],
        'saving_dues' => ['saving_type_id' => 'Rekap setoran bulanan per jenis', 'period' => 'Lembar setoran per bulan'],
        'orders' => ['member_id' => 'Pesanan per anggota', 'status' => 'Antrean pesanan'],
    ];

    /** @return list<array{table: string, rows: int, checks: list<array{column: string, purpose: string, ok: bool, index: ?string}>}> */
    public static function report(): array
    {
        $out = [];
        foreach (self::EXPECTED as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            try {
                $indexes = collect(Schema::getIndexes($table));
            } catch (Throwable) {
                $indexes = collect();
            }
            $checks = [];
            foreach ($columns as $column => $purpose) {
                $hit = $indexes->first(fn ($i) => ($i['columns'][0] ?? null) === $column);
                $checks[] = ['column' => $column, 'purpose' => $purpose, 'ok' => (bool) $hit, 'index' => $hit['name'] ?? null];
            }
            $out[] = ['table' => $table, 'rows' => DB::table($table)->count(), 'checks' => $checks];
        }

        return $out;
    }
}
