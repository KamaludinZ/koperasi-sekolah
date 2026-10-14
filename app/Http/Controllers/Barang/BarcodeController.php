<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductBarcode;
use App\Support\BarcodeImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Barcode barang (JSON, Admin & Pengurus):
 * - PUT  /barang/{product}/barcode {mode: nilai|baru|kode, barcode?} → ubah satu barang
 * - POST /barang/barcode/massal {ids[], mode: baru|kode, timpa?}     → buat nomor baru / kembali ke kode (maks. 500)
 */
class BarcodeController extends Controller
{
    public function update(Request $request, Product $product, ProductBarcode $barcodes): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['nilai', 'baru', 'kode'])],
            'barcode' => ['required_if:mode,nilai', 'nullable', 'string', 'max:64'],
        ], ['mode.in' => 'Pilih nilai, baru, atau kode.', 'barcode.required_if' => 'Isi nilai barcode.']);

        $product = $barcodes->assign($product, $data['mode'], $data['barcode'] ?? null);

        return response()->json(['data' => [
            'id' => $product->id, 'code' => $product->code, 'barcode' => $product->barcode,
            'value' => BarcodeImage::value($product), 'image' => route('barang.barcode.image', [$product->id, 'svg']),
        ]]);
    }

    public function bulk(Request $request, ProductBarcode $barcodes): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'mode' => ['required', Rule::in(['baru', 'kode'])],
            'timpa' => ['nullable', 'boolean'],
        ], ['ids.required' => 'Pilih barang terlebih dahulu.', 'ids.max' => 'Maksimal 500 barang sekali proses.']);

        return response()->json(['data' => $barcodes->assignMany($data['ids'], $data['mode'], (bool) ($data['timpa'] ?? false))]);
    }
}
