<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberCreditService;
use App\Services\SavingsPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint kasir untuk pembayaran atas nama anggota (menu Kasir, tanpa perlu menu Anggota):
 * - GET /kasir/anggota/cari?q=  → anggota aktif yang cocok (maks. 8) dengan saldo tabungan.
 * - GET /kasir/anggota/{member}/tabungan?total=  → cek saldo sebelum bayar:
 *   {member, can_pay, reason (ok|inactive|insufficient), balance, amount, shortfall, balance_after}.
 * - GET /kasir/anggota/{member}/bon?total=  → cek batas bon sebelum bayar:
 *   {member, can_pay, reason (ok|inactive|overdue|over_limit), amount, shortfall, limit, outstanding,
 *    available, open_bills, oldest_due_at, overdue, max_days}.
 * Penyimpanan pembayaran tetap lewat POST /kasir/transaksi (payment_method=tabungan|bon, member_id,
 * dan untuk bon credit_due_at + credit_note).
 */
class MemberPaymentController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']], ['q.min' => 'Ketik minimal 2 huruf.']);

        $members = Member::active()->search($data['q'])->orderByRaw('LOWER(name)')->limit(8)->get();

        return response()->json(['data' => $members->map(fn (Member $m) => self::member($m))->all()]);
    }

    public function savings(Request $request, Member $member, SavingsPaymentService $savings): JsonResponse
    {
        $data = $request->validate(['total' => ['required', 'integer', 'min:1', 'max:100000000']]);

        return response()->json(['member' => self::member($member)] + $savings->check($member, (int) $data['total']));
    }

    public function credit(Request $request, Member $member, MemberCreditService $credits): JsonResponse
    {
        $data = $request->validate(['total' => ['required', 'integer', 'min:1', 'max:100000000']]);

        return response()->json(['member' => self::member($member)] + $credits->check($member, (int) $data['total']) + ['max_days' => MemberCreditService::maxDays()]);
    }

    /** Data anggota secukupnya untuk kasir (tanpa nomor HP). */
    private static function member(Member $m): array
    {
        return [
            'id' => $m->id,
            'member_code' => $m->member_code,
            'name' => $m->name,
            'display_name' => $m->display_name,
            'type' => $m->type,
            'class' => $m->class,
            'balance' => (int) round((float) $m->balance),
            'is_active' => $m->is_active,
        ];
    }
}
