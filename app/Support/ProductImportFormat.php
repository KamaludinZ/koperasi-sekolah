<?php

namespace App\Support;

/**
 * Format berkas impor barang (CSV/XLSX): kolom yang dikenali, wajib/tidak, contoh, dan
 * keterangan. Baris pertama berkas = judul kolom (huruf besar/kecil & spasi diabaikan).
 * Dipakai halaman unggah, templat unduhan, dan pembaca impor.
 */
class ProductImportFormat
{
    /** kolom => [wajib, contoh, keterangan] */
    public const COLUMNS = [
        'kode' => [true, 'ATK010', 'Kode/barcode unik. Barang dengan kode yang sudah ada akan diperbarui.'],
        'nama' => [true, 'Spidol Papan Tulis', 'Nama barang, maks. 150 huruf.'],
        'kategori' => [true, 'Alat Tulis', 'Nama kategori yang sudah ada.'],
        'harga_jual' => [true, '8000', 'Angka tanpa titik/Rp.'],
        'harga_modal' => [false, '6500', 'Angka; kosong = 0.'],
        'stok' => [false, '24', 'Stok awal barang baru; barang lama hanya bila opsi perbarui stok dipilih.'],
        'batas_minimum' => [false, '5', 'Batas stok menipis; kosong = bawaan pengaturan.'],
        'satuan' => [false, 'pcs', 'pcs, bks, btl, kotak, lusin, rim, atau set; kosong = pcs.'],
        'aktif' => [false, 'ya', 'ya/tidak; kosong = ya.'],
    ];

    /** Cara menyimpan baris: tambah baru & perbarui yang ada, hanya tambah, atau hanya perbarui. */
    public const MODES = [
        'semua' => ['Tambah & perbarui', 'Barang baru ditambahkan; kode yang sudah ada diperbarui.'],
        'tambah' => ['Hanya tambah baru', 'Baris dengan kode yang sudah ada dilewati.'],
        'perbarui' => ['Hanya perbarui', 'Baris dengan kode baru dilewati.'],
    ];

    /** Maksimal baris data per berkas. */
    public const MAX_ROWS = 2000;

    /** @return list<string> */
    public static function headers(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** @return list<list<string>> judul kolom + dua baris contoh */
    public static function templateRows(): array
    {
        return [self::headers(), array_column(self::COLUMNS, 1), ['MNM010', 'Jus Jeruk Kotak', 'Minuman', '5000', '4000', '12', '6', 'kotak', 'ya']];
    }

    /** Isi templat CSV (pemisah titik koma, cocok untuk Excel berbahasa Indonesia). */
    public static function csvTemplate(): string
    {
        return "\xEF\xBB\xBF".implode("\r\n", array_map(fn ($r) => implode(';', $r), self::templateRows()))."\r\n";
    }

    public static function xlsxTemplate(): string
    {
        return SimpleXlsx::write(self::templateRows(), 'Barang');
    }
}
