<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Models\ProductImport;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Impor barang (JSON, Admin & Pengurus lewat gate kelola-katalog).
 * - POST /barang/impor {berkas (CSV/XLSX), mode: semua|tambah|perbarui, perbarui_stok?} → 201 impor baru
 *   Berkas diperiksa jenis, ukuran (pengaturan Keamanan & retensi), dan isinya, lalu langsung dibaca:
 *   status "pratinjau" (baris tersimpan) atau "gagal" (file_error).
 * - GET  /barang/impor/{import}/baris → impor + baris yang dinilai (baru/perbarui/lewati/masalah) & jumlahnya
 */
class ProductImportController extends Controller
{
    public function store(Request $request, ProductImportService $imports): JsonResponse
    {
        $data = $request->validate(ProductImportService::rules(), ProductImportService::messages());

        $import = $imports->upload($data['berkas'], $data['mode'], (bool) ($data['perbarui_stok'] ?? false), $request->user());

        return response()->json(['data' => ProductImportService::toArray($import)], 201);
    }

    public function rows(ProductImport $import, ProductImportService $imports): JsonResponse
    {
        return response()->json(['data' => ProductImportService::toArray($import->load('user'))] + $imports->evaluate($import));
    }
}
