<?php

namespace App\Http\Controllers\Anggota;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberCreditPayment;
use App\Services\MemberCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bon (piutang) satu anggota (menu Anggota):
 * - GET  /anggota/{member}/bon            → {summary, bills (belum lunas, jatuh tempo terlama dulu), payments (10 terbaru)}
 * - POST /anggota/{member}/bon/pelunasan  {amount, method: tunai|tabungan, note?} → 201 {data: pelunasan, summary}
 *   Dibagi ke bon jatuh tempo terlama dulu; tunai masuk laci shift terbuka, tabungan memotong saldo.
 */
class CreditController extends Controller
{
    public function index(Member $member, MemberCreditService $credits): JsonResponse
    {
        return response()->json([
            'summary' => $credits->summary($member),
            'bills' => $credits->openBills($member),
            'payments' => $credits->payments($member),
        ]);
    }

    public function repay(Request $request, Member $member, MemberCreditService $credits): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::in(array_keys(MemberCreditPayment::METHODS))],
            'note' => ['nullable', 'string', 'max:150'],
        ], ['amount.required' => 'Masukkan jumlah pelunasan.', 'method.in' => 'Cara pelunasan tidak dikenal.']);

        $payment = $credits->repay($member->id, (int) $data['amount'], $data['method'], $data['note'] ?? null, $request->user());

        return response()->json([
            'data' => MemberCreditService::paymentToArray($payment->load('payer:id,name')),
            'summary' => $credits->summary($member->fresh()),
        ], 201);
    }
}
