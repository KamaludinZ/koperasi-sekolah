<?php

namespace App\Http\Controllers\Laporan;

use App\Http\Controllers\Controller;
use App\Services\SalesReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Laporan penjualan (JSON):
 * - GET /laporan/hari-ini            → ringkasan hari ini vs kemarin s.d. jam yang sama,
 *                                      barang terlaris, penjualan per jam, dan 5 barang terlaris
 * - GET /laporan/ringkasan?periode=  → ringkasan periode + pembanding + barang terlaris
 * - GET /laporan/grafik?rentang=     → titik grafik per hari/minggu
 */
class SalesReportController extends Controller
{
    public function today(SalesReport $report): JsonResponse
    {
        $now = now();

        return response()->json([
            'data' => [
                ...$report->today(),
                'top_products' => $report->topProducts($now->copy()->startOfDay(), $now, 5),
            ],
            'meta' => [
                'date' => $now->toDateString(),
                'generated_at' => $now->toDateTimeString(),
            ],
        ]);
    }

    public function summary(Request $request, SalesReport $report): JsonResponse
    {
        $data = $request->validate(['periode' => ['nullable', Rule::in(array_keys(SalesReport::PERIODS))]]);
        $period = $data['periode'] ?? 'hari-ini';
        [$start, $end] = SalesReport::range($period);

        return response()->json([
            'data' => [
                ...$report->periodReport($period),
                'top_products' => $report->topProducts($start, $end, 5),
            ],
            'meta' => [
                'period' => $period,
                'from' => $start->toDateTimeString(),
                'to' => $end->toDateTimeString(),
            ],
        ]);
    }

    public function chart(Request $request, SalesReport $report): JsonResponse
    {
        $data = $request->validate(['rentang' => ['nullable', Rule::in(array_keys(SalesReport::CHART_PERIODS))]]);
        $range = $data['rentang'] ?? '7-hari';

        return response()->json([
            'data' => $report->chartPoints($range),
            'meta' => ['range' => $range, 'unit' => $range === '12-minggu' ? 'minggu' : 'hari'],
        ]);
    }
}
