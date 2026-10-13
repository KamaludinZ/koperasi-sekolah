<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Services\TrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Data terhapus lunak (menu Audit: Admin & Pengurus melihat; memulihkan khusus Admin).
 *
 * GET  /audit/terhapus/data?jenis=barang|kategori|anggota&q=
 *      → {data: [{key, entity, label, detail, deleted_at, deleted_by, reason, purge_at, days_left, conflict}], summary, can_restore}
 * POST /audit/terhapus/pulihkan {data: ["barang:15", ...], alasan?}
 *      → {restored: [...], failed: [{key, label, reason}]}; 422 bila tidak ada yang berhasil dipulihkan.
 */
class TrashController extends Controller
{
    public function index(Request $request, TrashService $trash): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['nullable', Rule::in(array_keys(TrashService::ENTITIES))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => $trash->list(['entity' => $data['jenis'] ?? '', 'search' => $data['q'] ?? '']),
            'summary' => $trash->summary(),
            'can_restore' => Gate::allows('pulihkan-data'),
        ]);
    }

    public function restore(Request $request, TrashService $trash): JsonResponse
    {
        $data = $request->validate([
            'data' => ['required', 'array', 'min:1', 'max:100'],
            'data.*' => ['string', 'regex:/^('.implode('|', array_keys(TrashService::ENTITIES)).'):\d+$/'],
            'alasan' => ['nullable', 'string', 'max:200'],
        ], [
            'data.required' => 'Pilih data yang akan dipulihkan.',
            'data.*.regex' => 'Format data: jenis:id, mis. barang:15.',
        ]);

        $result = $trash->restore($data['data'], $data['alasan'] ?? null);
        if ($result['restored'] === []) {
            return response()->json([
                'message' => $result['failed'][0]['reason'] ?? 'Data terhapus tidak ditemukan.',
                ...$result,
            ], 422);
        }

        return response()->json($result + ['message' => count($result['restored']).' data dipulihkan.']);
    }
}
