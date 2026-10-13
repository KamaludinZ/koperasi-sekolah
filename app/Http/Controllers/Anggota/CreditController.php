<?php

namespace App\Http\Controllers\Anggota;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberCreditPayment;
use App\Services\MemberCreditService;
use App\Services\TransactionHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bon (piutang) satu anggota (menu Anggota):
 * - GET  /anggota/{member}/bon            → {summary, bills (belum lunas, jatuh tempo terlama dulu), payments (10 terbaru)}
 * - POST /anggota/{member}/bon/pelunasan  {amount, method: tunai|tabungan, note?} → 201 {data: pelunasan, summary}
 *   Dibagi ke bon jatuh tempo terlama dulu; tunai masuk laci shift terbuka, tabungan memotong saldo.
 * Seluruh piutang:
 * - GET /anggota/piutang/daftar?q=&jenis=&status=(lewat|minggu|berjalan) → {stats, data: anggota berbon}
 * - GET /anggota/piutang/riwayat?q=&jenis=&catatan=(bon|pelunasan)&dari=&sampai= → {data, bon, paid}
 */
class CreditController extends Controller
{
    public function debtors(Request $request, MemberCreditService $credits): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'jenis' => ['nullable', Rule::in(array_keys(Member::TYPES))],
            'status' => ['nullable', Rule::in(['lewat', 'minggu', 'berjalan'])],
        ]);

        return response()->json([
            'stats' => $credits->stats(),
            'data' => $credits->debtors(['search' => $data['q'] ?? null, 'type' => $data['jenis'] ?? null, 'status' => $data['status'] ?? '']),
        ]);
    }

    public function history(Request $request, MemberCreditService $credits): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'jenis' => ['nullable', Rule::in(array_keys(Member::TYPES))],
            'catatan' => ['nullable', Rule::in(['bon', 'pelunasan'])],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);
        [$from, $to] = TransactionHistory::range('semua', $data['dari'] ?? null, $data['sampai'] ?? null);
        $result = $credits->history(['search' => $data['q'] ?? null, 'type' => $data['jenis'] ?? null, 'kind' => $data['catatan'] ?? '', 'from' => $from, 'to' => $to]);

        return response()->json(['data' => $result['rows'], 'bon' => $result['bon'], 'paid' => $result['paid']]);
    }

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
