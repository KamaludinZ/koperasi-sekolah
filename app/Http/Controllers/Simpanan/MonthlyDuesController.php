<?php

namespace App\Http\Controllers\Simpanan;

use App\Http\Controllers\Controller;
use App\Models\SavingType;
use App\Services\SavingDueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Setoran bulanan potong gaji (JSON, Admin & Pengurus):
 * - GET /simpanan/setoran-bulanan/data?bulan=YYYY-MM&jenis=      → lembar satu bulan (guru aktif) + ringkasan
 * - GET /simpanan/setoran-bulanan/rekap/data?jenis=&rentang=3|6|12 → rekap per bulan + matriks status per guru
 * - POST /simpanan/setoran-bulanan/{due}/koreksi {nominal, alasan}  → koreksi setoran (0 = batalkan posting); tercatat di audit
 */
class MonthlyDuesController extends Controller
{
    public function sheet(Request $request, SavingDueService $dues): JsonResponse
    {
        $data = $request->validate([
            'bulan' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'jenis' => ['required', 'integer', Rule::exists('saving_types', 'id')->where('period', 'bulanan')],
        ], ['bulan.regex' => 'Periode tidak valid.', 'jenis.exists' => 'Pilih jenis simpanan bulanan.']);
        $rows = collect($dues->sheet($data['bulan'], SavingType::findOrFail($data['jenis'])));
        $posted = $rows->where('status', 'disetor');

        return response()->json(['data' => $rows->values(), 'meta' => [
            'period' => $data['bulan'], 'members' => $rows->count(), 'posted' => $posted->count(), 'posted_amount' => (int) $posted->sum('amount'),
            'pending' => $rows->where('status', 'belum')->count(), 'missing' => $rows->count() - $posted->count(),
        ]]);
    }

    public function correct(Request $request, \App\Models\SavingDue $due, SavingDueService $dues): JsonResponse
    {
        $data = $request->validate([
            'nominal' => ['required', 'integer', 'min:0'],
            'alasan' => ['required', 'string', 'min:5', 'max:150'],
        ], ['alasan.required' => 'Tulis alasan koreksi.', 'alasan.min' => 'Alasan minimal 5 karakter.']);
        $before = (int) round((float) $due->amount);
        $due = $dues->correct($due, (int) $data['nominal'], $data['alasan'], $request->user());

        return response()->json(['data' => [
            'id' => $due->id, 'member_id' => $due->member_id, 'period' => $due->period, 'status' => $due->status,
            'amount' => (int) round((float) $due->amount), 'amount_before' => $before, 'note' => $due->note,
        ]]);
    }

    public function recap(Request $request, SavingDueService $dues): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['required', 'integer', Rule::exists('saving_types', 'id')->where('period', 'bulanan')],
            'rentang' => ['nullable', Rule::in([3, 6, 12])],
        ]);
        $type = SavingType::findOrFail($data['jenis']);
        $matrix = $dues->matrix($type, (int) ($data['rentang'] ?? 6));

        return response()->json(['data' => [
            'months' => $dues->recap($type, 12),
            'periods' => $matrix['periods'], 'totals' => $matrix['totals'], 'members' => $matrix['rows'],
        ]]);
    }
}
