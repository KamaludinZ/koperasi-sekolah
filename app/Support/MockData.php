<?php

namespace App\Support;

/**
 * Data tiruan untuk tahap frontend. Akan diganti query ke PostgreSQL
 * (tabel products & categories) saat layer backend dikerjakan.
 */
class MockData
{
    public static function categories(): array
    {
        return ['Alat Tulis', 'Makanan', 'Minuman', 'Seragam'];
    }

    /** Identitas koperasi & teks struk (nanti dari tabel settings). */
    public static function profile(): array
    {
        return [
            'name' => 'Koperasi Siswa Harapan Bangsa',
            'school' => 'SMP Negeri 1 Contoh',
            'address' => 'Jl. Pendidikan No. 1, Kota Contoh',
            'receipt_footer' => 'Terima kasih sudah berbelanja di koperasi sekolah!',
        ];
    }

    /** Persentase pajak penjualan (nanti dari tabel settings). */
    public static function taxRate(): float
    {
        return 0;
    }

    private const SESSION_KEY = 'mock.products';

    /**
     * Semua barang (aktif & nonaktif). Perubahan dari form barang disimpan di
     * session supaya bisa dicoba tanpa database.
     */
    public static function products(): array
    {
        $products = session(self::SESSION_KEY) ?? self::seedProducts();

        return array_map(fn ($p) => $p + ['is_active' => true], array_values($products));
    }

    /** Barang yang boleh dijual di layar kasir. */
    public static function activeProducts(): array
    {
        return array_values(array_filter(self::products(), fn ($p) => $p['is_active']));
    }

    public static function findProduct(int $id): ?array
    {
        return collect(self::products())->firstWhere('id', $id);
    }

    /** Simpan barang baru (tanpa id) atau perbarui barang yang ada. Mengembalikan barang tersimpan. */
    public static function saveProduct(array $data): array
    {
        $products = collect(self::products())->keyBy('id');
        $data['id'] ??= ($products->keys()->max() ?? 0) + 1;
        $products[$data['id']] = [...($products[$data['id']] ?? []), ...$data];
        session([self::SESSION_KEY => $products->values()->all()]);

        return $products[$data['id']];
    }

    private static function seedProducts(): array
    {
        return [
            ['id' => 1, 'code' => 'ATK001', 'name' => 'Buku Tulis 38 Lembar', 'category' => 'Alat Tulis', 'price' => 4000, 'cost_price' => 3200, 'stock' => 120, 'min_stock' => 20, 'unit' => 'pcs'],
            ['id' => 2, 'code' => 'ATK002', 'name' => 'Pulpen Hitam', 'category' => 'Alat Tulis', 'price' => 3000, 'cost_price' => 2400, 'stock' => 85, 'min_stock' => 20, 'unit' => 'pcs'],
            ['id' => 3, 'code' => 'ATK003', 'name' => 'Pensil 2B', 'category' => 'Alat Tulis', 'price' => 2500, 'cost_price' => 2000, 'stock' => 12, 'min_stock' => 15, 'unit' => 'pcs'],
            ['id' => 4, 'code' => 'ATK004', 'name' => 'Penghapus', 'category' => 'Alat Tulis', 'price' => 1500, 'cost_price' => 1200, 'stock' => 60, 'min_stock' => 10, 'unit' => 'pcs'],
            ['id' => 5, 'code' => 'ATK005', 'name' => 'Penggaris 30 cm', 'category' => 'Alat Tulis', 'price' => 3500, 'cost_price' => 2800, 'stock' => 0, 'min_stock' => 5, 'unit' => 'pcs'],
            ['id' => 6, 'code' => 'MKN001', 'name' => 'Roti Coklat', 'category' => 'Makanan', 'price' => 5000, 'cost_price' => 4000, 'stock' => 24, 'min_stock' => 10, 'unit' => 'pcs'],
            ['id' => 7, 'code' => 'MKN002', 'name' => 'Biskuit Kelapa', 'category' => 'Makanan', 'price' => 2000, 'cost_price' => 1600, 'stock' => 48, 'min_stock' => 10, 'unit' => 'bks'],
            ['id' => 8, 'code' => 'MKN003', 'name' => 'Keripik Singkong', 'category' => 'Makanan', 'price' => 3000, 'cost_price' => 2400, 'stock' => 6, 'min_stock' => 10, 'unit' => 'bks'],
            ['id' => 9, 'code' => 'MNM001', 'name' => 'Air Mineral 600 ml', 'category' => 'Minuman', 'price' => 3500, 'cost_price' => 2800, 'stock' => 72, 'min_stock' => 24, 'unit' => 'btl'],
            ['id' => 10, 'code' => 'MNM002', 'name' => 'Teh Kotak', 'category' => 'Minuman', 'price' => 4500, 'cost_price' => 3600, 'stock' => 30, 'min_stock' => 12, 'unit' => 'kotak'],
            ['id' => 11, 'code' => 'MNM003', 'name' => 'Susu UHT Coklat', 'category' => 'Minuman', 'price' => 6000, 'cost_price' => 4800, 'stock' => 18, 'min_stock' => 12, 'unit' => 'kotak'],
            ['id' => 12, 'code' => 'SRG001', 'name' => 'Dasi Sekolah', 'category' => 'Seragam', 'price' => 15000, 'cost_price' => 12000, 'stock' => 25, 'min_stock' => 5, 'unit' => 'pcs'],
            ['id' => 13, 'code' => 'SRG002', 'name' => 'Topi Sekolah', 'category' => 'Seragam', 'price' => 20000, 'cost_price' => 16000, 'stock' => 4, 'min_stock' => 5, 'unit' => 'pcs'],
            ['id' => 14, 'code' => 'SRG003', 'name' => 'Ikat Pinggang', 'category' => 'Seragam', 'price' => 18000, 'cost_price' => 14400, 'stock' => 14, 'min_stock' => 5, 'unit' => 'pcs'],
        ];
    }
}
