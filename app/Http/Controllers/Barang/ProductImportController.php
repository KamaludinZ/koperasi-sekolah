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
 * - PUT  /barang/impor/{import}/baris/{line} {values: {kolom: teks}} → perbaiki satu baris
 * - POST /barang/impor/{import}/baris/{line}/lewati {lewati: bool} → lewati/sertakan baris
 * - POST /barang/impor/{import}/lewati-masalah → lewati semua baris bermasalah
 * - POST /barang/impor/{import}/simpan → terapkan ke data barang (ditolak bila masih ada baris bermasalah)
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

    public function updateRow(Request $request, ProductImport $import, int $line, ProductImportService $imports): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array'], 'values.*' => ['nullable', 'string', 'max:200']]);
        $imports->updateRow($import, $line, $data['values']);

        return $this->rows($import, $imports);
    }

    public function skipRow(Request $request, ProductImport $import, int $line, ProductImportService $imports): JsonResponse
    {
        $data = $request->validate(['lewati' => ['required', 'boolean']]);
        $imports->setSkipped($import, $line, (bool) $data['lewati']);

        return $this->rows($import, $imports);
    }

    public function skipProblems(ProductImport $import, ProductImportService $imports): JsonResponse
    {
        $imports->skipProblems($import);

        return $this->rows($import, $imports);
    }

    public function commit(Request $request, ProductImport $import, ProductImportService $imports): JsonResponse
    {
        $import = $imports->commit($import, $request->user());

        return response()->json(['data' => ProductImportService::toArray($import->load('user'))]);
    }
}
