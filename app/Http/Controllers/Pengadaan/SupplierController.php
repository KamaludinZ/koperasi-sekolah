<?php

namespace App\Http\Controllers\Pengadaan;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Supplier (JSON; menu Pengadaan, ubah lewat gate kelola-pengadaan). Semua perubahan tercatat di audit.
 * - GET    /pengadaan/supplier/data?status=aktif|nonaktif|semua&kategori=&q=&urut=nama|pembelian|hutang|terakhir
 * - GET    /pengadaan/supplier/{supplier}/data → rincian + pesanan + hutang berjalan
 * - POST   /pengadaan/supplier                  → {name, contact?, phone, email?, address?, terms_days, note?, is_active?, categories[]} (201)
 * - PUT    /pengadaan/supplier/{supplier}       → ubah
 * - DELETE /pengadaan/supplier/{supplier}       → {alasan?} hapus lunak (ditolak bila ada pesanan berjalan/hutang)
 */
class SupplierController extends Controller
{
    public function index(Request $request, SupplierService $suppliers): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif', 'semua'])],
            'kategori' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'urut' => ['nullable', Rule::in(['nama', 'pembelian', 'hutang', 'terakhir'])],
        ]);

        return response()->json(['data' => $suppliers->list(['status' => $f['status'] ?? 'semua', 'category' => $f['kategori'] ?? '',
            'search' => $f['q'] ?? '', 'sort' => $f['urut'] ?? 'nama'])]);
    }

    public function show(Supplier $supplier, SupplierService $suppliers): JsonResponse
    {
        return response()->json(['data' => $suppliers->detail($supplier)]);
    }

    public function store(Request $request, SupplierService $suppliers): JsonResponse
    {
        $data = $request->validate(SupplierService::rules(), SupplierService::messages());

        return response()->json(['data' => SupplierService::toArray($suppliers->create($data))], 201);
    }

    public function update(Request $request, Supplier $supplier, SupplierService $suppliers): JsonResponse
    {
        $data = $request->validate(SupplierService::rules($supplier->id), SupplierService::messages());

        return response()->json(['data' => SupplierService::toArray($suppliers->update($supplier, $data))]);
    }

    public function destroy(Request $request, Supplier $supplier, SupplierService $suppliers): JsonResponse
    {
        $data = $request->validate(['alasan' => ['nullable', 'string', 'max:200']]);
        $suppliers->delete($supplier, $data['alasan'] ?? null);

        return response()->json(['message' => "Supplier {$supplier->name} dihapus. Data dapat dipulihkan Admin selama ".Supplier::retentionDays().' hari.']);
    }
}
