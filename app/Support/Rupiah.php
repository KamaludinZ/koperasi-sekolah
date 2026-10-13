<?php

namespace App\Support;

/**
 * Format rupiah seragam untuk seluruh layar: "Rp12.500" (tanpa spasi, titik ribuan, tanpa desimal).
 * Tanda minus memakai "−" (U+2212) agar sejajar dengan angka; `signed` menambah "+" untuk nilai positif.
 * Di Blade: @rupiah($n). Di Alpine/JS: window.rupiah(n) (resources/js/app.js) dengan aturan sama.
 */
class Rupiah
{
    public static function format(int|float|string|null $amount, bool $signed = false): string
    {
        $n = (int) round((float) $amount);
        $sign = $n < 0 ? '−' : ($signed && $n > 0 ? '+' : '');

        return $sign.'Rp'.number_format(abs($n), 0, ',', '.');
    }

    /** Angka tanpa "Rp" (mis. baris barang di struk thermal). */
    public static function number(int|float|string|null $amount): string
    {
        return number_format((int) round((float) $amount), 0, ',', '.');
    }
}
