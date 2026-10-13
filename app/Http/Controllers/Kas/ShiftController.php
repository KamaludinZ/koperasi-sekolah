<?php

namespace App\Http\Controllers\Kas;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET  /kas/shift/aktif                 Shift yang sedang terbuka ({data: null} bila tidak ada).
 * POST /kas/shift/buka                  {name: Shift pagi|siang|sore, opening_cash} → 201; 422 bila masih ada shift terbuka.
 * POST /kas/shift/{shift}/tutup         {counted_cash, note?} → 200 dengan kas seharusnya, uang dihitung & selisih;
 *                                        422 bila sudah ditutup atau selisih tanpa catatan.
 * Membuka/menutup shift khusus petugas yang boleh memakai layar kasir (gate `kelola-shift`).
 */
class ShiftController extends Controller
{
    public function current(ShiftService $shifts): JsonResponse
    {
        $shift = $shifts->current();

        return response()->json(['data' => $shift ? ShiftService::toArray($shift) : null]);
    }

    public function open(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate(ShiftService::rules()['open'], ShiftService::messages());
        $shift = $shifts->open($data['name'], (int) $data['opening_cash'], $request->user());

        return response()->json(['data' => ShiftService::toArray($shift)], 201);
    }

    public function close(Request $request, CashShift $shift, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate(ShiftService::rules()['close'], ShiftService::messages());
        $shift = $shifts->close($shift, (int) $data['counted_cash'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => ShiftService::toArray($shift)]);
    }
}
