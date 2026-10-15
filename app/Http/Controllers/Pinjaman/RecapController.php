<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Services\LoanRecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rekap pinjaman (JSON, Admin & Pengurus):
 * - GET /pinjaman/rekap/berjalan → ringkasan pinjaman berjalan saat ini (jumlah, pokok, kewajiban, dibayar, sisa,
 *   tunggakan, sebaran per jenis anggota/metode/sisa angsuran, perkiraan masuk 30 hari) + posisi portofolio.
 * - GET /pinjaman/rekap/data?jenis=bulan|tahun|rentang&bulan=YYYY-MM&tahun=YYYY&dari=&sampai= → rekap periode
 *   (pengajuan, pencairan, angsuran diterima per metode & pokok/bunga, rincian per bulan) sama dengan halaman Rekap.
 *   Bawaan bulan berjalan; periode masa depan / rentang > 366 hari / format salah → 422.
 */
class RecapController extends Controller
{
    public function running(LoanRecapService $recap): JsonResponse
    {
        return response()->json(['data' => $recap->running(), 'portfolio' => $recap->portfolio(), 'generated_at' => now()->toIso8601String()]);
    }

    public function period(Request $request, LoanRecapService $recap): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['nullable', Rule::in(['bulan', 'tahun', 'rentang'])],
            'bulan' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'tahun' => ['nullable', 'regex:/^\d{4}$/'],
            'dari' => ['required_if:jenis,rentang', 'nullable', 'date_format:Y-m-d'],
            'sampai' => ['required_if:jenis,rentang', 'nullable', 'date_format:Y-m-d'],
        ], ['jenis.in' => 'Jenis periode tidak dikenal.', 'bulan.regex' => 'Bulan harus berformat YYYY-MM.', 'tahun.regex' => 'Tahun tidak valid.']);
        $type = $data['jenis'] ?? 'bulan';

        if ($type === 'rentang') {
            $period = LoanRecapService::range($data['dari'], $data['sampai']);
        } else {
            $value = $type === 'tahun' ? ($data['tahun'] ?? today()->format('Y')) : ($data['bulan'] ?? today()->format('Y-m'));
            $future = $type === 'tahun' ? $value > today()->format('Y') : $value > today()->format('Y-m');
            $period = $future || $value < '2000' ? null : LoanRecapService::period($type, $value);
        }
        if (! $period) {
            return response()->json(['message' => 'Periode tidak sah (tidak boleh melewati hari ini; rentang maks. '.LoanRecapService::MAX_RANGE_DAYS.' hari).',
                'errors' => ['periode' => ['Periode tidak sah.']]], 422);
        }
        [$from, $to, $label] = $period;

        return response()->json(['data' => $recap->recap($from, $to), 'period' => ['type' => $type, 'label' => $label, 'from' => $from->toDateString(), 'to' => $to->toDateString()]]);
    }
}
