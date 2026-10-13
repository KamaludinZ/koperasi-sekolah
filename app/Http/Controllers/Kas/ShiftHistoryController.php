<?php

namespace App\Http\Controllers\Kas;

use App\Http\Controllers\Controller;
use App\Livewire\Kas\Recap;
use App\Models\CashShift;
use App\Models\Role;
use App\Models\User;
use App\Services\CashReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GET /kas/shift/riwayat?periode=|dari=&sampai=&petugas={user_id}&status=kurang|lebih|pas|berjalan&page=
 *     Riwayat shift (terbaru dulu, 25 per halaman) + ringkasan selisih per petugas pada periode itu
 *     + daftar petugas untuk saringan. Rentang maks. 92 hari. Kasir hanya melihat shift miliknya.
 *     Rincian shift lain ditolak untuk kasir (CashShiftPolicy::view).
 * GET /kas/shift/{shift}/rincian
 *     Satu shift lengkap: angka kas, setoran/penarikan, dan daftar penjualannya.
 */
class ShiftHistoryController extends Controller
{
    public function index(Request $request, CashReportService $report): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', Rule::in(array_keys(Recap::PERIODS))],
            'dari' => ['nullable', 'required_with:sampai', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'required_with:dari', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'petugas' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'status' => ['nullable', Rule::in(array_keys(CashReportService::DIFFERENCE_STATUSES))],
            'page' => ['nullable', 'integer', 'min:1'],
        ], ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);

        [$from, $to] = isset($data['dari'])
            ? [Carbon::parse($data['dari']), Carbon::parse($data['sampai'])]
            : Recap::rangeFor($data['periode'] ?? '7-hari');
        if ($from->diffInDays($to) + 1 > CashReportService::MAX_RANGE_DAYS) {
            return response()->json(['message' => 'Rentang maksimal 92 hari.', 'errors' => ['dari' => ['Rentang maksimal 92 hari.']]], 422);
        }

        // Kasir hanya melihat riwayat & selisih shift miliknya sendiri.
        $own = $request->user()->hasRole(Role::KASIR) ? $request->user()->id : null;
        $userId = $own ?? ($data['petugas'] ?? null);

        $page = $report->history($from, $to, $userId, $data['status'] ?? null)->withQueryString();
        $cashierIds = $own ? collect([$own]) : CashShift::whereBetween('opened_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->distinct()->pluck('user_id');
        $perCashier = $report->perCashier($from, $to);

        return response()->json([
            'data' => $page->items(),
            'per_cashier' => $own ? array_values(array_filter($perCashier, fn ($p) => $p['user_id'] === $own)) : $perCashier,
            'cashiers' => User::whereKey($cashierIds)->orderBy('name')->get(['id', 'name']),
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'links' => ['prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
        ]);
    }

    public function show(CashShift $shift, CashReportService $report): JsonResponse
    {
        ShiftController::authorizeOrAudit('view', $shift);
        return response()->json(['data' => $report->shiftDetail($shift)]);
    }
}
