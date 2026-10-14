<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Livewire\Pinjaman\Applications;
use App\Models\Loan;
use App\Services\LoanApplicationService;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengajuan pinjaman (JSON, Admin & Pengurus):
 * - GET  /pinjaman/pengajuan/data?tahap=diajukan|disetujui|ditolak|dibatalkan&q=&page= → daftar per tahap + jumlah per tahap
 * - POST /pinjaman/pengajuan → catat pengajuan baru (201). Bunga: `interest` (rupiah) atau `interest_rate` (persen dari pokok).
 */
class LoanApplicationController extends Controller
{
    public function index(Request $request, LoanService $loans): JsonResponse
    {
        $data = $request->validate([
            'tahap' => ['nullable', Rule::in(array_keys(Applications::TABS))],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], ['tahap.in' => 'Tahap pengajuan tidak dikenal.']);
        $stage = $data['tahap'] ?? 'diajukan';

        $paginator = $loans->query(['search' => $data['q'] ?? '', 'status' => $stage])->paginate(Applications::PER_PAGE)->withQueryString();
        $counts = $loans->stats()['counts'];

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Loan $l) => LoanService::toArray($l))->all(),
            'meta' => [
                'stage' => $stage, 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(),
                'counts' => collect(Applications::TABS)->map(fn ($label, $s) => $counts[$s] ?? 0)->all(),
            ],
            'links' => ['prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl()],
        ]);
    }

    public function store(Request $request, LoanApplicationService $applications): JsonResponse
    {
        if (! $request->filled('interest') && $request->filled('interest_rate')) {
            $request->validate(['interest_rate' => ['numeric', 'min:0', 'max:100', 'decimal:0,2'], 'principal' => ['required', 'integer']], [
                'interest_rate.numeric' => 'Persen bunga harus berupa angka.', 'interest_rate.min' => 'Persen bunga tidak boleh negatif.',
                'interest_rate.max' => 'Persen bunga maksimal 100%.', 'interest_rate.decimal' => 'Persen bunga maksimal 2 angka di belakang koma.',
                'principal.required' => 'Isi nominal pinjaman.', 'principal.integer' => 'Nominal harus berupa angka.',
            ]);
            $request->merge(['interest' => (int) round((int) $request->input('principal') * (float) $request->input('interest_rate') / 100)]);
        }
        $request->mergeIfMissing(['applied_at' => today()->toDateString()]);

        $data = $request->validate(LoanApplicationService::rules(), LoanApplicationService::messages());
        $loan = $applications->create($data, $request->user()?->id);

        return response()->json([
            'data' => LoanService::toArray($loan),
            'message' => "Pengajuan {$loan->code} untuk {$loan->member->name} dicatat dan menunggu keputusan.",
        ], 201);
    }
}
