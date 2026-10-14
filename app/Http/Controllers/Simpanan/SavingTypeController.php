<?php

namespace App\Http\Controllers\Simpanan;

use App\Http\Controllers\Controller;
use App\Models\SavingType;
use App\Services\SavingTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jenis simpanan (JSON, Admin & Pengurus — kasir tidak punya menu Simpanan):
 * - GET    /simpanan/jenis/data             → semua jenis + jumlah anggota bersaldo & total saldo
 * - POST   /simpanan/jenis                  → tambah (201)
 * - PUT    /simpanan/jenis/{type}           → ubah
 * - PATCH  /simpanan/jenis/{type}/status    {aktif} → aktif/nonaktif
 * - DELETE /simpanan/jenis/{type}           → hapus bila belum pernah dipakai
 */
class SavingTypeController extends Controller
{
    public function index(SavingTypeService $types): JsonResponse
    {
        $stats = $types->stats();

        return response()->json(['data' => SavingType::ordered()->get()->map(fn (SavingType $t) => SavingTypeService::toArray($t)
            + ['members' => $stats[$t->id]['members'] ?? 0, 'total_balance' => $stats[$t->id]['balance'] ?? 0])->all()]);
    }

    public function store(Request $request, SavingTypeService $types): JsonResponse
    {
        $data = $request->validate(SavingTypeService::rules(null, $request->input('kind')), SavingTypeService::messages());

        return response()->json(['data' => SavingTypeService::toArray($types->save($data))], 201);
    }

    public function update(Request $request, SavingType $type, SavingTypeService $types): JsonResponse
    {
        $data = $request->validate(SavingTypeService::rules($type->id, $request->input('kind')), SavingTypeService::messages());

        return response()->json(['data' => SavingTypeService::toArray($types->save($data, $type))]);
    }

    public function status(Request $request, SavingType $type, SavingTypeService $types): JsonResponse
    {
        $data = $request->validate(['aktif' => ['required', 'boolean']], ['aktif.required' => 'Wajib diisi.']);

        return response()->json(['data' => SavingTypeService::toArray($types->setActive($type, (bool) $data['aktif']))]);
    }

    public function destroy(SavingType $type, SavingTypeService $types): JsonResponse
    {
        $types->delete($type);

        return response()->json(['message' => "{$type->name} dihapus."]);
    }
}
