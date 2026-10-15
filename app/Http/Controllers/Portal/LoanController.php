<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\MemberLoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * GET /portal/pinjaman/data?tampil=semua|berjalan|pengajuan|selesai (anggota yang masuk portal, JSON):
 * pinjaman milik anggota itu saja — tidak ada parameter id, jadi pinjaman anggota lain tidak bisa diminta.
 * Respons: data (status, nominal, sisa & progres, angsuran berikutnya, tunggakan, rincian sisa pokok/bunga, riwayat
 * pembayaran sah), summary (sisa berjalan, angsuran terdekat, tunggakan), counts per tab. Nama petugas, catatan
 * internal, dan pembayaran yang dikoreksi tidak ikut.
 *
 * GET /portal/pinjaman/sisa → sisa pinjaman berjalan & angsuran berikutnya: total sisa (pokok/bunga), tunggakan,
 * tagihan s.d. akhir bulan ini, angsuran terdekat, dan per pinjaman aktif jadwal angsuran yang belum lunas.
 *
 * GET /portal/pinjaman/{loan}/riwayat → riwayat angsuran satu pinjaman MILIK anggota (pinjaman orang lain atau yang
 * tidak ada sama-sama 404, tanpa membedakan): pembayaran sah terbaru dulu (metode, rujukan, angsuran ke-, sisa setelah
 * bayar), total dibayar per metode, ringkasan pokok/bunga, dan status tiap angsuran.
 */
class LoanController extends Controller
{
    public function index(Request $request, MemberLoanService $loans): JsonResponse
    {
        $data = $request->validate(['tampil' => ['nullable', Rule::in(array_keys(MemberLoanService::TABS))]],
            ['tampil.in' => 'Saringan tidak dikenal.']);
        $member = Auth::guard('member')->user();
        abort_unless($member, 401);

        $all = $loans->forMember($member);
        $tab = $data['tampil'] ?? 'semua';

        return response()->json([
            'data' => MemberLoanService::filter($all, $tab),
            'summary' => MemberLoanService::totals($all),
            'counts' => MemberLoanService::counts($all),
            'filters' => ['tampil' => $tab],
        ]);
    }

    public function outstanding(MemberLoanService $loans): JsonResponse
    {
        $member = Auth::guard('member')->user();
        abort_unless($member, 401);

        return response()->json(['data' => $loans->outstanding($member)]);
    }

    public function history(int $loan, MemberLoanService $loans): JsonResponse
    {
        $member = Auth::guard('member')->user();
        abort_unless($member, 401);

        return response()->json(['data' => $loans->history(MemberLoanService::ownedLoan($member, $loan))]);
    }
}
