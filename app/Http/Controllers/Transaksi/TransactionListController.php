<?php

namespace App\Http\Controllers\Transaksi;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use App\Services\TransactionHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GET /transaksi/daftar?dari=&sampai=&metode=&q=&per_halaman=&page=
 * Riwayat transaksi berpaginasi (JSON), terbaru dulu.
 */
class TransactionListController extends Controller
{
    public function __invoke(Request $request, TransactionHistory $history): JsonResponse
    {
        $data = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'metode' => ['nullable', Rule::in(array_keys(CheckoutService::PAYMENT_METHODS))],
            'q' => ['nullable', 'string', 'max:50'],
            'per_halaman' => ['nullable', 'integer', Rule::in(TransactionHistory::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);

        $paginator = $history->paginate([
            'from' => isset($data['dari']) ? Carbon::createFromFormat('Y-m-d', $data['dari'])->startOfDay() : null,
            'to' => isset($data['sampai']) ? Carbon::createFromFormat('Y-m-d', $data['sampai'])->endOfDay() : null,
            'method' => $data['metode'] ?? null,
            'search' => $data['q'] ?? '',
        ], (int) ($data['per_halaman'] ?? TransactionHistory::PER_PAGE_OPTIONS[0]))
            ->withQueryString();

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
