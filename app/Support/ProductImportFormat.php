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

    /** Nilai "aktif" yang dikenali. */
    private const YES = ['ya', 'y', 'yes', '1', 'aktif', 'true'];

    private const NO = ['tidak', 't', 'no', '0', 'nonaktif', 'false'];

    /**
     * Rapikan satu baris mentah (kolom => teks): trim, kode huruf besar, angka tanpa titik/Rp.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    public static function clean(array $raw): array
    {
        $out = [];
        foreach (self::headers() as $col) {
            $v = trim((string) ($raw[$col] ?? ''));
            $out[$col] = match ($col) {
                'kode' => mb_strtoupper($v),
                'harga_jual', 'harga_modal', 'stok', 'batas_minimum' => preg_replace('/^rp\s*|[.\s]/i', '', $v),
                'satuan', 'aktif' => mb_strtolower($v),
                default => preg_replace('/\s+/', ' ', $v),
            };
        }

        return $out;
    }

    /**
     * Periksa satu baris yang sudah dirapikan.
     *
     * @param  list<string>  $categories  nama kategori yang ada
     * @param  array<string, int>  $seenCodes  kode → nomor baris pertama di berkas (untuk duplikat)
     * @return array<string, string> kolom => pesan kesalahan
     */
    public static function validateRow(array $row, array $categories, array $seenCodes = [], ?int $line = null): array
    {
        $errors = [];
        $int = fn ($v) => preg_match('/^\d+$/', $v) === 1;

        if ($row['kode'] === '') {
            $errors['kode'] = 'Kode wajib diisi.';
        } elseif (! preg_match('/^[A-Z0-9_-]{1,50}$/', $row['kode'])) {
            $errors['kode'] = 'Kode hanya huruf, angka, - dan _ (maks. 50).';
        } elseif (isset($seenCodes[$row['kode']]) && $seenCodes[$row['kode']] !== $line) {
            $errors['kode'] = 'Kode sama dengan baris '.$seenCodes[$row['kode']].'.';
        }
        if ($row['nama'] === '') {
            $errors['nama'] = 'Nama wajib diisi.';
        } elseif (mb_strlen($row['nama']) > 150) {
            $errors['nama'] = 'Nama maksimal 150 huruf.';
        }
        if ($row['kategori'] === '') {
            $errors['kategori'] = 'Kategori wajib diisi.';
        } elseif (! in_array(mb_strtolower($row['kategori']), array_map('mb_strtolower', $categories), true)) {
            $errors['kategori'] = 'Kategori "'.$row['kategori'].'" belum ada.';
        }
        if ($row['harga_jual'] === '') {
            $errors['harga_jual'] = 'Harga jual wajib diisi.';
        } elseif (! $int($row['harga_jual']) || (int) $row['harga_jual'] > 1_000_000_000) {
            $errors['harga_jual'] = 'Harga jual harus angka bulat.';
        }
        foreach (['harga_modal' => 'Harga modal', 'stok' => 'Stok', 'batas_minimum' => 'Batas minimum'] as $col => $label) {
            if ($row[$col] !== '' && (! $int($row[$col]) || (int) $row[$col] > 1_000_000_000)) {
                $errors[$col] = "{$label} harus angka bulat 0 atau lebih.";
            }
        }
        if (! isset($errors['harga_jual'], $errors['harga_modal']) && $row['harga_modal'] !== '' && $row['harga_jual'] !== ''
            && $int($row['harga_modal']) && $int($row['harga_jual']) && (int) $row['harga_modal'] > (int) $row['harga_jual']) {
            $errors['harga_modal'] = 'Harga modal lebih besar dari harga jual.';
        }
        if ($row['satuan'] !== '' && ! in_array($row['satuan'], \App\Services\ProductService::UNITS, true)) {
            $errors['satuan'] = 'Satuan tidak dikenal.';
        }
        if ($row['aktif'] !== '' && ! in_array($row['aktif'], [...self::YES, ...self::NO], true)) {
            $errors['aktif'] = 'Isi ya atau tidak.';
        }

        return $errors;
    }

    /** "ya"/"tidak" → bool (kosong = aktif). */
    public static function isActive(string $value): bool
    {
        return $value === '' || in_array(mb_strtolower($value), self::YES, true);
    }
}
