<?php

namespace App\Services;

use App\Models\Product;

/**
 * Nilai barcode barang: validasi keunikan (terhadap barcode & kode barang lain) dan pembuatan nomor baru.
 * Nomor baru memakai EAN-13 berawalan 200–299 (rentang GS1 untuk pemakaian internal toko), jadi tidak
 * bentrok dengan barcode pabrik; tetap dicetak sebagai CODE128.
 */
class ProductBarcode
{
    public const PATTERN = '/^[A-Za-z0-9\-.\/+]{3,64}$/';

    /** Nilai barcode/kode sudah dipakai barang lain (sebagai barcode atau kode)? */
    public static function taken(string $value, ?int $ignoreId = null): bool
    {
        $v = mb_strtolower(trim($value));

        return Product::withTrashed()
            ->where(fn ($q) => $q->whereRaw('LOWER(barcode) = ?', [$v])->orWhereRaw('LOWER(code) = ?', [$v]))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /** Barcode tersimpan: kosong atau sama dengan kode barang sendiri = ikut kode (null). */
    public static function normalize(?string $barcode, string $code): ?string
    {
        $b = trim((string) $barcode);

        return $b === '' || mb_strtolower($b) === mb_strtolower(trim($code)) ? null : $b;
    }

    /** Nomor EAN-13 internal baru yang belum dipakai. */
    public function generate(?int $ignoreId = null): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = self::ean13('2'.str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT));
            if (! self::taken($code, $ignoreId)) {
                return $code;
            }
        }

        throw new \RuntimeException('Gagal membuat nomor barcode unik.');
    }

    /**
     * Ubah barcode banyak barang sekaligus.
     * - mode `baru`: beri nomor EAN-13 internal baru; barang yang sudah punya barcode khusus dilewati kecuali $overwrite.
     * - mode `kode`: kembalikan ke kode barang (barcode dikosongkan).
     * Tiap perubahan tercatat per barang (MasterDataObserver) plus satu ringkasan di audit.
     *
     * @param  list<int>  $ids
     * @return array{changed: int, skipped: int}
     */
    public function assignMany(array $ids, string $mode, bool $overwrite = false): array
    {
        if (! in_array($mode, ['baru', 'kode'], true)) {
            throw new \InvalidArgumentException("Mode barcode tidak dikenal: {$mode}");
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return \Illuminate\Support\Facades\DB::transaction(function () use ($ids, $mode, $overwrite) {
            $changed = $skipped = 0;
            foreach (Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $p) {
                $keep = $mode === 'baru' ? (filled($p->barcode) && ! $overwrite) : blank($p->barcode);
                if ($keep) {
                    $skipped++;

                    continue;
                }
                $p->update(['barcode' => $mode === 'baru' ? $this->generate($p->id) : null]);
                $changed++;
            }
            if ($changed) {
                AuditLog::record('product.barcode_bulk', 'barang',
                    ($mode === 'baru' ? "Nomor barcode baru dibuat untuk {$changed} barang" : "{$changed} barang dikembalikan ke barcode kode barang")
                        .($skipped ? " ({$skipped} dilewati)" : ''),
                    null, ['mode' => $mode, 'overwrite' => $overwrite, 'changed' => $changed, 'skipped' => $skipped, 'ids' => $ids]);
            }

            return ['changed' => $changed, 'skipped' => $skipped];
        });
    }

    /** Lengkapi 12 digit dengan digit pemeriksa EAN-13. */
    public static function ean13(string $twelve): string
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 ? 3 : 1);
        }

        return $twelve.((10 - $sum % 10) % 10);
    }
}
