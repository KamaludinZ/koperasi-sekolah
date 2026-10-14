<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Support\LabelFormats;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * GET /barang/barcode/label/cetak?barang=12:3,15:1&format=a4-3x8&nama=1&harga=1&otomatis=1
 * Tampilan label barcode siap cetak (ukuran kertas & label dalam mm, satu lembar per halaman cetak).
 * GET /barang/barcode/label/unduh?… → versi PDF (LabelPdfService).
 */
class LabelPrintController extends Controller
{
    /** GET /barang/barcode/label/unduh?barang=&format=&nama=&harga= → PDF label siap cetak. */
    public function pdf(Request $request, \App\Services\LabelPdfService $labels): \Symfony\Component\HttpFoundation\Response
    {
        $data = $this->validated($request);
        $r = $labels->render(LabelFormats::parseItems($data['barang']), $data['format'] ?? LabelFormats::DEFAULT,
            (bool) ($data['nama'] ?? true), (bool) ($data['harga'] ?? true));
        abort_if($r['labels'] === 0, 422, 'Tidak ada label untuk dicetak.');

        return response($r['content'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$r['name'].'"']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'barang' => ['required', 'string', 'max:5000', 'regex:/^\d+(:\d+)?(,\d+(:\d+)?)*$/'],
            'format' => ['nullable', Rule::in(array_keys(LabelFormats::FORMATS))],
            'nama' => ['nullable', 'boolean'],
            'harga' => ['nullable', 'boolean'],
            'otomatis' => ['nullable', 'boolean'],
        ]);
    }

    public function __invoke(Request $request): View
    {
        $data = $this->validated($request);
        $items = LabelFormats::parseItems($data['barang']);
        $f = LabelFormats::get($data['format'] ?? LabelFormats::DEFAULT);
        $sheets = LabelFormats::sheets($items, $f['key']);

        return view('barang.label-print', [
            'f' => $f,
            'pages' => $sheets['pages'],
            'total' => $sheets['total'],
            'truncated' => $sheets['truncated'],
            'showName' => (bool) ($data['nama'] ?? true),
            'showPrice' => (bool) ($data['harga'] ?? true),
            'autoPrint' => (bool) ($data['otomatis'] ?? false),
            'back' => route('barang.label', ['barang' => $data['barang'], 'format' => $f['key']]),
        ]);
    }
}
