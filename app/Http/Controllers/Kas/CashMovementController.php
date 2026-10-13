<?php

namespace App\Http\Controllers\Kas;

use App\Http\Controllers\Controller;
use App\Services\CashMovementService;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET  /kas/mutasi?tanggal=Y-m-d   Setoran & penarikan kas satu hari (bawaan hari ini) + rekap
 *                                  masuk/keluar/bersih & per kategori.
 * POST /kas/mutasi                 {type: in|out, category, amount, reason} → 201, dicatat pada shift
 *                                  terbuka; 422 bila belum ada shift atau penarikan melebihi isi laci.
 *                                  Khusus petugas kasir (gate `kelola-shift`).
 */
class CashMovementController extends Controller
{
    public function index(Request $request, CashMovementService $movements): JsonResponse
    {
        $data = $request->validate(['tanggal' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);

        return response()->json($movements->forDate($data['tanggal'] ?? today()->toDateString()));
    }

    public function store(Request $request, CashMovementService $movements, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate(CashMovementService::rules(), CashMovementService::messages());
        $movement = $movements->record($data['type'], $data['category'], (int) $data['amount'], $data['reason'], $request->user());

        return response()->json([
            'data' => CashMovementService::toArray($movement),
            'shift' => ShiftService::toArray($movement->shift->fresh('user:id,name')),
        ], 201);
    }
}
