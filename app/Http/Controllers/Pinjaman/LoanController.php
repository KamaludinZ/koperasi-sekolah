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
 * - GET /pinjaman/ringkasan → jumlah & nominal per status, per metode, angsuran jatuh tempo bulan ini & terlambat
 * - GET /pinjaman/{loan}/data → rincian satu pinjaman (pencairan, jadwal angsuran, ringkasan bayar)
 * - GET /pinjaman/{loan}/angsuran?status=belum|dibayar|menunggak|terlambat → jadwal angsuran saja (+ ringkasan)
 * Setiap pinjaman di daftar & rincian membawa is_overdue, effective_status (menunggak bila ada angsuran lewat jatuh
 * tempo walau kolom status belum disegarkan), overdue (jumlah/nominal tertunggak, jatuh tempo tertua, lama terlambat)
 * dan overdue_since; filter status=menunggak/berjalan memakai keadaan efektif.
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
            'data' => (function () use ($paginator) {
                $flags = LoanService::overdueFlags(collect($paginator->items())->pluck('id')->all());

                return collect($paginator->items())->map(fn (Loan $l) => LoanService::withOverdue(LoanService::toArray($l), $flags[$l->id] ?? null))->all();
            })(),
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

    public function summary(LoanService $loans): JsonResponse
    {
        return response()->json(['data' => $loans->summary()]);
    }

    public function show(Loan $loan, LoanService $loans): JsonResponse
    {
        return response()->json(['data' => $loans->detail($loan)]);
    }

    public function installments(Request $request, Loan $loan, LoanService $loans): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['belum', 'dibayar', 'menunggak', 'terlambat'])]],
            ['status.in' => 'Status angsuran tidak dikenal.']);
        $detail = $loans->detail($loan);
        $rows = collect($detail['installments'])->when($data['status'] ?? null, fn ($c, $status) => $c->filter(
            fn ($i) => $status === 'terlambat' ? $i['late'] : $i['status'] === $status))->values()->all();

        return response()->json(['data' => $rows, 'meta' => ['loan' => ['id' => $loan->id, 'code' => $loan->code, 'status' => $loan->status,
            'effective_status' => $detail['loan']['effective_status'], 'overdue' => $detail['loan']['overdue']], 'summary' => $detail['summary']]]);
    }

    /**
     * GET /pinjaman/{loan}/audit?event= → jejak audit satu pinjaman (terbaru dulu, maks. 500): peristiwa, pelaku &
     * perannya, waktu, IP, nilai sebelum/sesudah, alasan; plus jumlah baris riwayat perubahan angsuran.
     */
    public function audit(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate(['event' => ['nullable', 'string', 'max:60', 'regex:/^pinjaman\.[a-z_]+$/']],
            ['event.regex' => 'Peristiwa tidak dikenal.']);
        $rows = \App\Models\ActivityLog::query()->where('subject_type', 'loan')->where('subject_id', $loan->id)
            ->when($data['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(500)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id, 'event' => $r->event, 'description' => $r->description, 'actor' => $r->actor_name, 'actor_role' => $r->actor_role,
                'at' => $r->created_at?->toDateTimeString(), 'ip' => $r->ip_address, 'amount' => $r->amount, 'action' => $r->action,
                'old' => $r->old_values, 'new' => $r->new_values, 'reason' => $r->reason,
            ])->all(),
            'meta' => ['loan' => ['id' => $loan->id, 'code' => $loan->code], 'count' => $rows->count(),
                'installment_changes' => $loan->installmentChanges()->count()],
        ]);
    }
}
