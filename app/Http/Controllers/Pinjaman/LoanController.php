<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pinjaman anggota (JSON, Admin & Pengurus):
 * - GET /pinjaman/daftar?q=&status=&metode=&anggota=&per_halaman=&page= → daftar berpaginasi + ringkasan status
 * - GET /pinjaman/{loan}/data → rincian satu pinjaman (pencairan, jadwal angsuran, ringkasan bayar)
 */
class LoanController extends Controller
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public function index(Request $request, LoanService $loans): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(Loan::STATUSES))],
            'metode' => ['nullable', Rule::in(array_keys(Loan::METHODS))],
            'anggota' => ['nullable', 'integer', 'min:1'],
            'per_halaman' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'status.in' => 'Status pinjaman tidak dikenal.',
            'metode.in' => 'Metode bayar tidak dikenal.',
            'per_halaman.in' => 'Jumlah per halaman harus 25, 50, atau 100.',
        ]);

        $paginator = $loans->query([
            'search' => $data['q'] ?? '',
            'status' => $data['status'] ?? '',
            'method' => $data['metode'] ?? '',
            'member_id' => $data['anggota'] ?? null,
        ])->paginate((int) ($data['per_halaman'] ?? self::PER_PAGE_OPTIONS[0]))->withQueryString();

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Loan $l) => LoanService::toArray($l))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'stats' => $loans->stats(),
                'statuses' => Loan::STATUSES,
                'methods' => Loan::METHODS,
            ],
            'links' => ['prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl()],
        ]);
    }

    public function show(Loan $loan, LoanService $loans): JsonResponse
    {
        return response()->json(['data' => $loans->detail($loan)]);
    }
}
