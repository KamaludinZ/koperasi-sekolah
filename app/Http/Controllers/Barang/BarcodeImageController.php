<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\BarcodeImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /barang/{product}/barcode.svg            → gambar barcode CODE128 barang (SVG mandiri)
 * GET /barang/{product}/barcode.png?modul=&tinggi= → PNG (modul 1–6 px, tinggi 20–300 px)
 * Nilai = barcode khusus barang atau kode barang. ETag mengikuti nilai, jadi label lama tetap di-cache.
 */
class BarcodeImageController extends Controller
{
    public function __invoke(Request $request, Product $product, string $format): Response
    {
        $value = BarcodeImage::value($product);
        $etag = '"'.sha1($value.'|'.$format.'|'.$request->query('modul').'|'.$request->query('tinggi')).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        if ($format === 'png') {
            $png = BarcodeImage::png($value, (int) $request->query('modul', 2), (int) $request->query('tinggi', 60));
            abort_unless($png !== null, 422, 'Barcode tidak bisa dibuat.');

            return response($png, 200, ['Content-Type' => 'image/png', 'ETag' => $etag, 'Cache-Control' => 'private, max-age=3600',
                'Content-Disposition' => 'inline; filename="barcode-'.$value.'.png"']);
        }

        $svg = BarcodeImage::svgDocument($value, 60);
        abort_unless($svg !== null, 422, 'Barcode tidak bisa dibuat.');

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'ETag' => $etag, 'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff']);
    }
}
