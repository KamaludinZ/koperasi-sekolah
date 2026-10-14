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
 */
class MemberSavingController extends Controller
{
    public function show(Member $member, MemberSavingService $savings): JsonResponse
    {
        return response()->json(['member' => MemberService::toArray($member), 'data' => $savings->summary($member)]);
    }

    public function mine(MemberSavingService $savings): JsonResponse
    {
        $member = Auth::guard('member')->user();

        return response()->json(['data' => $savings->summary($member) + ['tabungan' => (int) round((float) $member->balance)]]);
    }
}
