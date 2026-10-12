<?php

namespace App\Http\Controllers\Anggota;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\MemberService;
use App\Services\SavingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET  /anggota/{member}/tabungan?periode=&dari=&sampai=&jenis=&per_halaman=&page=
 * Saldo + ringkasan tabungan anggota dan riwayat mutasi berpaginasi (terbaru dulu)
 * beserta total masuk/keluar sesuai filter.
 *
 * POST /anggota/{member}/tabungan  {type: setor|tarik, amount, note?}
 * Catat setoran/penarikan tabungan (201) dan kembalikan mutasi + data anggota terbaru.
 * Ditolak (422) bila anggota nonaktif, nominal di luar batas, atau penarikan melebihi saldo.
 */
class SavingsController extends Controller
{
    public function index(Request $request, Member $member, SavingsService $savings): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', Rule::in(array_keys(SavingsService::HISTORY_PRESETS))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'jenis' => ['nullable', Rule::in(array_keys(SavingsTransaction::TYPES))],
            'per_halaman' => ['nullable', 'integer', Rule::in(SavingsService::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ], ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);

        [$from, $to] = SavingsService::historyRange($data['periode'] ?? 'semua', $data['dari'] ?? null, $data['sampai'] ?? null);
        $history = $savings->history($member, [
            'from' => $from,
            'to' => $to,
            'type' => $data['jenis'] ?? null,
        ], (int) ($data['per_halaman'] ?? SavingsService::PER_PAGE_OPTIONS[0]));
        $paginator = $history['paginator']->withQueryString();

        return response()->json([
            'member' => MemberService::toArray($member),
            'stats' => $savings->stats($member),
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'in' => $history['in'],
                'out' => $history['out'],
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function store(Request $request, Member $member, SavingsService $savings): JsonResponse
    {
        $data = $request->validate(SavingsService::rules(), SavingsService::messages());

        $entry = $savings->record($member, $data['type'], (int) $data['amount'], $data['note'] ?? null, $request->user()?->id);

        return response()->json([
            'data' => SavingsService::toArray($entry->load('user:id,name')),
            'member' => MemberService::toArray($member),
        ], 201);
    }
}
