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
 * GET /transaksi/daftar?periode=&dari=&sampai=&metode=&q=&per_halaman=&page=
 * Riwayat transaksi berpaginasi (JSON), terbaru dulu.
 * GET /transaksi/ringkasan?periode=&dari=&sampai=&metode=
 * Ringkasan penjualan rentang tersebut + perbandingan periode sebelumnya.
 * `periode` = preset cepat (hari-ini, kemarin, 7-hari, bulan-ini); dari/sampai menimpanya.
 */
class TransactionListController extends Controller
{
    public function index(Request $request, TransactionHistory $history): JsonResponse
    {
        $data = $this->filters($request, [
            'q' => ['nullable', 'string', 'max:50'],
            'per_halaman' => ['nullable', 'integer', Rule::in(TransactionHistory::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        [$from, $to] = $this->range($data);

        $paginator = $history->paginate([
            'from' => $from,
            'to' => $to,
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
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function summary(Request $request, TransactionHistory $history): JsonResponse
    {
        $data = $this->filters($request);
        [$from, $to] = $this->range($data);

        return response()->json([
            'data' => $history->summary($from, $to, $data['metode'] ?? null),
            'meta' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'label' => TransactionHistory::rangeLabel($from, $to),
            ],
        ]);
    }

    private function filters(Request $request, array $extra = []): array
    {
        return $request->validate([
            'periode' => ['nullable', Rule::in(array_keys(TransactionHistory::PRESETS))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'metode' => ['nullable', Rule::in(array_keys(CheckoutService::PAYMENT_LABELS))],
            ...$extra,
        ], [
            'sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ]);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(array $data): array
    {
        return TransactionHistory::range($data['periode'] ?? 'semua', $data['dari'] ?? null, $data['sampai'] ?? null);
    }
}
