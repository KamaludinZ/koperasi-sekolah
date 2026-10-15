<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\LoanArrearsQuery;
use App\Support\LoanOverdue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /pinjaman/tunggakan/data (Admin & Pengurus): daftar pinjaman menunggak, sama dengan halaman Tunggakan.
 * Parameter: umur (1-30|31-60|61-90|90+), metode, q (≤100), urut (terlama|terbesar), page, per_page (1–100, bawaan 30);
 * parameter tidak dikenal → 422. Respons: data (anggota & kontak, kode, jumlah & nominal tertunggak, jatuh tempo tertua,
 * lama terlambat, kelompok umur, sisa pinjaman, metode, menunggak sejak), meta (halaman), summary (total & per umur
 * — dari seluruh tunggakan, tidak terpengaruh saringan), filters, grace_days.
 */
class ArrearsController extends Controller
{
    public function index(Request $request, LoanArrearsQuery $query): JsonResponse
    {
        $data = $request->validate([
            'umur' => ['nullable', Rule::in(array_keys(LoanArrearsQuery::BUCKETS))],
            'metode' => ['nullable', Rule::in(array_keys(Loan::METHODS))],
            'q' => ['nullable', 'string', 'max:100'],
            'urut' => ['nullable', Rule::in(array_keys(LoanArrearsQuery::SORTS))],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], ['umur.in' => 'Kelompok umur tidak dikenal.', 'metode.in' => 'Metode bayar tidak dikenal.', 'urut.in' => 'Urutan tidak dikenal.',
            'per_page.max' => 'Maksimal 100 baris per halaman.']);

        $all = $query->all();
        $rows = LoanArrearsQuery::filter($all, ['bucket' => $data['umur'] ?? '', 'method' => $data['metode'] ?? '', 'search' => $data['q'] ?? '', 'sort' => $data['urut'] ?? 'terlama']);
        $per = (int) ($data['per_page'] ?? 30);
        $page = (int) ($data['page'] ?? 1);
        $since = Loan::query()->whereIn('id', $rows->pluck('id'))->pluck('overdue_since', 'id');

        return response()->json([
            'data' => $rows->forPage($page, $per)->map(fn ($r) => $r + ['overdue_since' => $since[$r['id']]?->toDateString()])->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $per)), 'per_page' => $per, 'total' => $rows->count()],
            'summary' => LoanArrearsQuery::summary($all),
            'filters' => ['umur' => $data['umur'] ?? null, 'metode' => $data['metode'] ?? null, 'q' => $data['q'] ?? null, 'urut' => $data['urut'] ?? 'terlama'],
            'grace_days' => LoanOverdue::graceDays(),
        ]);
    }
}
