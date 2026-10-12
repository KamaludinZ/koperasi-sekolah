<?php

namespace App\Http\Controllers\Anggota;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberService;
use App\Services\SavingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /anggota/{member}/tabungan  {type: setor|tarik, amount, note?}
 * Catat setoran/penarikan tabungan (201) dan kembalikan mutasi + data anggota terbaru.
 * Ditolak (422) bila anggota nonaktif, nominal di luar batas, atau penarikan melebihi saldo.
 */
class SavingsController extends Controller
{
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
