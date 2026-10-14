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

    /**
     * GET /portal/simpanan/mutasi?riwayat=simpanan|tabungan&periode=30-hari|90-hari|tahun-ini|semua&jenis=&limit=
     * Mutasi milik anggota yang masuk (terbaru dulu) + total masuk/keluar sesuai saringan.
     */
    public function myEntries(\Illuminate\Http\Request $request): JsonResponse
    {
        $data = $request->validate([
            'riwayat' => ['nullable', \Illuminate\Validation\Rule::in(['simpanan', 'tabungan'])],
            'periode' => ['nullable', \Illuminate\Validation\Rule::in(['30-hari', '90-hari', 'tahun-ini', 'semua'])],
            'jenis' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $id = Auth::guard('member')->id();
        $from = match ($data['periode'] ?? 'semua') {
            '30-hari' => now()->subDays(29)->startOfDay(), '90-hari' => now()->subDays(89)->startOfDay(),
            'tahun-ini' => now()->startOfYear(), default => null,
        };
        $tabungan = ($data['riwayat'] ?? 'simpanan') === 'tabungan';
        $query = ($tabungan
            ? \App\Models\SavingsTransaction::where('member_id', $id)
            : \App\Models\MemberSavingEntry::with('savingType:id,name')->where('member_id', $id)->when($data['jenis'] ?? null, fn ($q, $t) => $q->where('saving_type_id', $t)))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from));
        $totals = (clone $query)->toBase()->selectRaw("COALESCE(SUM(CASE WHEN type = 'setor' THEN amount ELSE 0 END), 0) AS masuk, COALESCE(SUM(CASE WHEN type <> 'setor' THEN amount ELSE 0 END), 0) AS keluar")->first();
        $rows = $query->latest('created_at')->latest('id')->limit((int) ($data['limit'] ?? 50))->get();

        return response()->json([
            'data' => $rows->map(fn ($e) => ['at' => $e->created_at->toIso8601String(), 'type' => $e->type, 'amount' => (int) round((float) $e->amount),
                'balance_after' => (int) round((float) $e->balance_after), 'note' => $e->note, 'period' => $e->period ?? null,
                'saving_type' => $tabungan ? null : $e->savingType?->name])->values(),
            'meta' => ['riwayat' => $tabungan ? 'tabungan' : 'simpanan', 'in' => (int) round((float) $totals->masuk), 'out' => (int) round((float) $totals->keluar)],
        ]);
    }

    public function mine(MemberSavingService $savings): JsonResponse
    {
        $member = Auth::guard('member')->user();

        return response()->json(['data' => $savings->summary($member) + ['tabungan' => (int) round((float) $member->balance)]]);
    }
}
