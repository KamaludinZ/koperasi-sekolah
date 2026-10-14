<?php

namespace App\Services;

use App\Support\BarcodeImage;
use App\Support\LabelFormats;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF label barcode (dompdf): ukuran kertas & label persis dalam mm sesuai LabelFormats, satu halaman
 * per lembar. Barcode dirender PNG (data URI) karena dukungan SVG dompdf terbatas.
 */
class LabelPdfService
{
    /** @param  array<int, int>  $items  id barang → salinan */
    public function render(array $items, string $format, bool $showName = true, bool $showPrice = true): array
    {
        $f = LabelFormats::get($format);
        $sheet = LabelFormats::sheets($items, $f['key']);
        $images = [];
        foreach ($sheet['pages'] as $page) {
            foreach ($page as $label) {
                $images[$label['barcode']] ??= ($png = BarcodeImage::png($label['barcode'], 3, 90)) ? 'data:image/png;base64,'.base64_encode($png) : null;
            }
        }

        $pdf = Pdf::loadView('barang.label-pdf', ['f' => $f, 'pages' => $sheet['pages'], 'images' => $images, 'showName' => $showName, 'showPrice' => $showPrice])
            ->setPaper([0, 0, $f['page_w'] * ReceiptPdfService::PT_PER_MM, $f['page_h'] * ReceiptPdfService::PT_PER_MM])
            ->output();

        return ['content' => $pdf, 'labels' => $sheet['total'], 'pages' => count($sheet['pages']), 'truncated' => $sheet['truncated'],
            'name' => 'label-barcode-'.$f['key'].'-'.now()->format('Ymd-His').'.pdf'];
    }
}
