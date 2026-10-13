<?php

namespace App\Http\Controllers\Kas;

use App\Http\Controllers\Controller;
use App\Livewire\Kas\Recap;
use App\Services\CashReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GET /kas/rekap/harian?tanggal=Y-m-d
 *     Rekap kas satu hari (bawaan hari ini): shift, setoran/penarikan, penjualan per metode, ringkasan.
 * GET /kas/rekap/periode?periode=7-hari|30-hari|bulan-ini|bulan-lalu  atau  ?dari=&sampai=
 *     Ringkasan kas per hari + total periode (maks. 92 hari, tidak melewati hari ini).
 */
class CashReportController extends Controller
{
    public function day(Request $request, CashReportService $report): JsonResponse
    {
        $data = $request->validate(['tanggal' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']],
            ['tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.']);

        return response()->json(['data' => $report->day($data['tanggal'] ?? today()->toDateString())]);
    }

    public function period(Request $request, CashReportService $report): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', Rule::in(array_keys(Recap::PERIODS))],
            'dari' => ['nullable', 'required_with:sampai', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'required_with:dari', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ], ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);

        [$from, $to] = isset($data['dari'])
            ? [Carbon::parse($data['dari']), Carbon::parse($data['sampai'])]
            : Recap::rangeFor($data['periode'] ?? '7-hari');
        if ($from->diffInDays($to) + 1 > CashReportService::MAX_RANGE_DAYS) {
            return response()->json(['message' => 'Rentang maksimal 92 hari.', 'errors' => ['dari' => ['Rentang maksimal 92 hari.']]], 422);
        }

        $rows = $report->range($from, $to);
        $open = collect($rows)->where('closed_day', false);

        return response()->json([
            'data' => array_reverse($rows),
            'meta' => [
                'from' => $from->toDateString(),
                'to' => min($to, today())->toDateString(),
                'totals' => [
                    'revenue' => $open->sum('revenue'),
                    'cash_sales' => $open->sum('cash_sales'),
                    'non_cash' => $open->sum('non_cash'),
                    'cash_in' => $open->sum('cash_in'),
                    'cash_out' => $open->sum('cash_out'),
                    'difference' => $open->sum('difference'),
                    'short' => $open->where('difference', '<', 0)->sum('difference'),
                    'over' => $open->where('difference', '>', 0)->sum('difference'),
                    'days' => $open->where('shifts', '>', 0)->count(),
                ],
            ],
        ]);
    }
}
