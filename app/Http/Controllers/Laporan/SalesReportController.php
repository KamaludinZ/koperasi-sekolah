<?php

namespace App\Http\Controllers\Laporan;

use App\Http\Controllers\Controller;
use App\Services\SalesReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

/**
 * Laporan penjualan (JSON):
 * - GET /laporan/hari-ini            → ringkasan hari ini vs kemarin s.d. jam yang sama,
 *                                      barang terlaris, penjualan per jam, dan 5 barang terlaris
 * - GET /laporan/ringkasan?periode=  → ringkasan periode + pembanding + barang terlaris
 * - GET /laporan/grafik?rentang=     → titik grafik pilihan cepat (7-hari, 30-hari, 12-minggu)
 * - GET /laporan/grafik?dari=&sampai=&satuan= → titik grafik rentang kustom; satuan
 *                                      hari|minggu|bulan (kosong = otomatis menurut panjang rentang)
 *   Keduanya menyertakan total, jumlah transaksi, dan titik tertinggi.
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
        $data = $request->validate([
            'rentang' => ['nullable', Rule::in(array_keys(SalesReport::CHART_PERIODS))],
            'dari' => ['nullable', 'required_with:sampai', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sampai' => ['nullable', 'required_with:dari', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'satuan' => ['nullable', Rule::in(SalesReport::CHART_UNITS)],
        ], [
            'sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'required_with' => 'Isi tanggal awal dan akhir.',
            'before_or_equal' => 'Tanggal tidak boleh melewati hari ini.',
        ]);

        if (isset($data['dari'])) {
            $start = Carbon::createFromFormat('!Y-m-d', $data['dari'])->startOfDay();
            $end = Carbon::createFromFormat('!Y-m-d', $data['sampai'])->endOfDay()->min(now());
            $unit = $data['satuan'] ?? SalesReport::unitFor($start, $end);

            if ($unit === 'hari' && $start->diffInDays($end) > 366) {
                throw ValidationException::withMessages(['satuan' => 'Rentang lebih dari 1 tahun: pilih per minggu atau per bulan.']);
            }

            $points = $report->chart($start, $end, $unit);
            $meta = ['range' => 'kustom', 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'unit' => $unit];
        } else {
            $range = $data['rentang'] ?? '7-hari';
            $points = $report->chartPoints($range);
            $meta = ['range' => $range, 'from' => $points[0]['date'], 'to' => now()->toDateString(), 'unit' => $range === '12-minggu' ? 'minggu' : 'hari'];
        }

        $peak = collect($points)->sortByDesc('value')->first();

        return response()->json([
            'data' => $points,
            'meta' => [
                ...$meta,
                'total' => array_sum(array_column($points, 'value')),
                'count' => array_sum(array_column($points, 'count')),
                'peak' => $peak && $peak['value'] > 0 ? ['date' => $peak['date'], 'label' => $peak['tooltip'], 'value' => $peak['value']] : null,
            ],
        ]);
    }
}
