<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Services\LoanInstallmentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /pinjaman/angsuran/data (Admin & Pengurus): daftar angsuran lintas pinjaman, sama dengan halaman Angsuran.
 * Parameter: tampil (bulan_ini|terlambat|belum|dibayar|semua, bawaan bulan_ini), bulan (Y-m jatuh tempo),
 * metode (potong_gaji|transfer|tunai — metode pinjaman), q (kode pinjaman/nama/kode anggota, ≤100), page, per_page (1–100,
 * bawaan 30). Parameter tidak dikenal ditolak 422 (bukan diabaikan) agar klien tahu filternya salah.
 * Respons: data (angsuran + pinjaman & anggota, porsi pokok/bunga, sisa, terlambat berapa hari), meta (halaman, total),
 * summary (jatuh tempo bulan ini, terlambat, terbayar bulan ini), filters (yang berlaku).
 */
class InstallmentController extends Controller
{
    public function index(Request $request, LoanInstallmentQuery $query): JsonResponse
    {
        $data = $request->validate([
            'tampil' => ['nullable', Rule::in(array_keys(LoanInstallmentQuery::FILTERS))],
            'bulan' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'metode' => ['nullable', Rule::in(array_keys(Loan::METHODS))],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'tampil.in' => 'Saringan tidak dikenal.', 'bulan.regex' => 'Bulan harus berformat YYYY-MM.',
            'metode.in' => 'Metode bayar tidak dikenal.', 'q.max' => 'Kata kunci maksimal 100 karakter.',
            'per_page.max' => 'Maksimal 100 baris per halaman.',
        ]);
        $filters = ['filter' => $data['tampil'] ?? 'bulan_ini', 'month' => $data['bulan'] ?? '', 'method' => $data['metode'] ?? '', 'search' => $data['q'] ?? ''];
        if ($filters['filter'] === 'bulan_ini') {
            $filters['month'] = ''; // sama dengan halaman: "bulan ini" mengabaikan pilihan bulan
        }

        $page = $query->query($filters)->paginate((int) ($data['per_page'] ?? 30), ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (LoanInstallment $i) => LoanInstallmentQuery::toArray($i) + [
                'principal_part' => (int) round((float) $i->principal_part), 'interest_part' => (int) round((float) $i->interest_part),
                'status_label' => LoanInstallment::STATUSES[$i->status] ?? $i->status,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => $query->summary(),
            'filters' => ['tampil' => $filters['filter'], 'bulan' => $filters['month'] ?: null, 'metode' => $filters['method'] ?: null, 'q' => $filters['search'] ?: null],
        ]);
    }
}
