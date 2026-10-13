<?php

namespace Tests\Concerns;

use App\Models\ProductImport;
use App\Services\ProductImportService;
use App\Support\ProductImportFormat;
use Illuminate\Http\UploadedFile;

/** Membuat impor barang sungguhan dari CSV contoh (baris 2–14) untuk test pratinjau & riwayat. */
trait MakesProductImports
{
    protected function sampleImportCsv(): string
    {
        $rows = [
            ['ATK001', 'Buku Tulis 38 Lembar', 'Alat Tulis', '4.500', '3200', '120', '20', 'pcs', 'ya'],
            ['ATK002', 'Pulpen Hitam', 'Alat Tulis', '3000', '2400', '85', '20', 'pcs', 'ya'],
            ['atk010', 'Spidol Papan Tulis', 'Alat Tulis', '8000', '6500', '24', '5', 'pcs', 'ya'],
            ['ATK011', 'Map Plastik', 'Alat Tulis', 'Rp 2.500', '1800', '50', '10', 'pcs', ''],
            ['MKN004', 'Wafer Coklat', 'Makanan', '2500', '2000', '40', '10', 'bks', 'ya'],
            ['MKN005', 'Kacang Atom', 'Snack', '2000', '1500', '30', '10', 'bks', 'ya'],
            ['MNM004', 'Jus Jeruk Kotak', 'Minuman', '5000', '4000', '12', '6', 'kotak', 'ya'],
            ['MNM005', '', 'Minuman', '3000', '2500', '24', '6', 'btl', 'ya'],
            ['MNM006', 'Teh Botol', 'Minuman', 'tiga ribu', '2500', '24', '6', 'btl', 'ya'],
            ['SRG004', 'Bet Lokasi', 'Seragam', '6000', '4500', '40', '10', 'pcs', 'ya'],
            ['SRG005', 'Kaos Kaki Putih', 'Seragam', '9000', '9500', '20', '5', 'pasang', 'ya'],
            ['ATK010', 'Spidol Papan Tulis Biru', 'Alat Tulis', '8000', '6500', '12', '5', 'pcs', 'ya'],
            ['SRG002', 'Topi Sekolah', 'Seragam', '20000', '16000', '4', '5', 'pcs', 'tidak'],
        ];

        return implode(';', ProductImportFormat::headers())."\n".implode("\n", array_map(fn ($r) => implode(';', $r), $rows))."\n";
    }

    protected function makeImport(string $mode = 'semua', bool $updateStock = false, ?string $content = null, string $name = 'barang-semester-ganjil.csv'): ProductImport
    {
        return app(ProductImportService::class)->upload(
            UploadedFile::fake()->createWithContent($name, $content ?? $this->sampleImportCsv()), $mode, $updateStock);
    }
}
