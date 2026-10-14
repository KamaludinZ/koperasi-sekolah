<?php

namespace App\Http\Controllers\Simpanan;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberSavingService;
use App\Services\MemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Saldo simpanan per jenis (JSON):
 * - GET /simpanan/anggota/{member}/saldo → pengurus/admin: saldo tiap jenis, status (lunas/belum/tunggak), tunggakan, total
 * - GET /portal/simpanan/data            → anggota yang masuk portal: data yang sama milik sendiri + saldo tabungan
 * - POST /simpanan/anggota/{member}/mutasi {jenis_id, arah: setor|tarik, nominal?, bulan?, catatan?}
 *   → setor/tarik per jenis (201) dengan validasi saldo ketat; wajib bulanan disetor per bulan (`bulan` = jumlah bulan).
 *   Header Idempotency-Key mencegah mutasi dobel.
 */
class MemberSavingController extends Controller
{
    public function show(Member $member, MemberSavingService $savings): JsonResponse
    {
        return response()->json(['member' => MemberService::toArray($member), 'data' => $savings->summary($member)]);
    }

    public function store(\Illuminate\Http\Request $request, Member $member, MemberSavingService $savings): JsonResponse
    {
        $data = $request->validate([
            'jenis_id' => ['required', 'integer', 'exists:saving_types,id'],
            'arah' => ['required', \Illuminate\Validation\Rule::in(['setor', 'tarik'])],
            'nominal' => ['nullable', 'integer', 'min:0', 'max:'.MemberSavingService::MAX_AMOUNT],
            'bulan' => ['nullable', 'integer', 'min:1', 'max:'.MemberSavingService::MAX_MONTHS],
            'catatan' => ['nullable', 'string', 'max:150'],
        ], ['jenis_id.exists' => 'Jenis simpanan tidak dikenal.', 'arah.in' => 'Pilih setor atau tarik.']);
        $ref = $request->header('Idempotency-Key');
        if ($ref !== null && ! preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $ref)) {
            return response()->json(['message' => 'Idempotency-Key tidak valid.', 'errors' => ['client_ref' => ['Kunci idempoten tidak valid.']]], 422);
        }
        $replayed = $ref !== null && \App\Models\MemberSavingEntry::where('client_ref', $ref)->exists();
        $type = \App\Models\SavingType::findOrFail($data['jenis_id']);
        $entries = $data['arah'] === 'setor'
            ? $savings->deposit($member, $type, (int) ($data['nominal'] ?? 0), $data['catatan'] ?? null, $request->user()?->id, (int) ($data['bulan'] ?? 1), $ref)
            : [$savings->withdraw($member, $type, (int) ($data['nominal'] ?? 0), $data['catatan'] ?? null, $request->user()?->id, $ref)];

        return response()->json([
            'data' => array_map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'amount' => (int) round((float) $e->amount), 'period' => $e->period,
                'balance_after' => (int) round((float) $e->balance_after)], $entries),
            'summary' => $savings->summary($member->refresh()),
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    public function mine(MemberSavingService $savings): JsonResponse
    {
        $member = Auth::guard('member')->user();

        return response()->json(['data' => $savings->summary($member) + ['tabungan' => (int) round((float) $member->balance)]]);
    }
}
