<?php

namespace App\Support;

use Picqer\Barcode\Renderers\PngRenderer;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Gambar barcode CODE128 (SVG inline, skalanya mengikuti lebar wadah) untuk tampilan barang & label.
 * Nilai barcode barang = kolom `barcode` bila ada, selain itu kode barang (bawaan PRD).
 */
class BarcodeImage
{
    /** Nilai barcode barang (array baris atau model). */
    public static function value(array|object $product): string
    {
        $p = (array) ($product instanceof \Illuminate\Database\Eloquent\Model ? $product->getAttributes() : $product);

        return (string) (($p['barcode'] ?? null) ?: ($p['code'] ?? ''));
    }

    /**
     * PNG CODE128 (latar putih, `$module` piksel per modul, tinggi `$height` px) untuk PDF/aplikasi lain.
     * Null bila nilai kosong atau tidak bisa dikodekan.
     */
    public static function png(string $value, int $module = 2, int $height = 60): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 64) {
            return null;
        }
        try {
            $barcode = (new TypeCode128)->getBarcode($value);

            return (new PngRenderer)->useGd()->setBackgroundColor([255, 255, 255])
                ->render($barcode, $barcode->getWidth() * max(1, min(6, $module)), max(20, min(300, $height)));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Dokumen SVG mandiri (dengan deklarasi XML & ukuran asli) untuk diunduh/ditautkan. */
    public static function svgDocument(string $value, float $height = 60): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 64) {
            return null;
        }
        try {
            $barcode = (new TypeCode128)->getBarcode($value);

            return (new SvgRenderer)->setBackgroundColor([255, 255, 255])->render($barcode, $barcode->getWidth() * 2, $height);
        } catch (\Throwable) {
            return null;
        }
    }

    /** SVG inline (tanpa deklarasi XML); null bila nilai kosong atau tidak bisa dikodekan CODE128. */
    public static function svg(string $value, float $height = 48): ?string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 64) {
            return null;
        }
        try {
            $barcode = (new TypeCode128)->getBarcode($value);
            $renderer = (new SvgRenderer)->setSvgType(SvgRenderer::TYPE_SVG_INLINE);
            $svg = $renderer->render($barcode, $barcode->getWidth() * 2, $height);
        } catch (\Throwable) {
            return null;
        }

        // Skala mengikuti lebar wadah (viewBox sudah ada): buang ukuran tetap & id yang bisa ganda di satu halaman.
        $svg = preg_replace('/<svg([^>]*?)\swidth="[\d.]+"\sheight="[\d.]+"/', '<svg$1 preserveAspectRatio="none" role="img" aria-label="Barcode '.e($value).'"', $svg, 1);

        return str_replace(' id="bars"', '', $svg);
    }
}
