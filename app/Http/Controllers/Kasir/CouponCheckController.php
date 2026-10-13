<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /kasir/kupon/cek  {code, subtotal, member_id?}
 * Periksa kode kupon untuk keranjang: {ok, reason, message, coupon, amount}. `reason`:
 * ok | empty | not_found | inactive | not_started | expired | quota | min_purchase | per_member.
 * Selalu 200 (umpan balik untuk kasir), dibatasi 30 permintaan/menit agar kode tidak bisa ditebak beruntun.
 */
class CouponCheckController extends Controller
{
    public function __invoke(Request $request, CouponService $coupons): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'subtotal' => ['required', 'integer', 'min:0', 'max:100000000'],
            'member_id' => ['nullable', 'integer', 'exists:members,id'],
        ], ['code.required' => 'Masukkan kode kupon.']);

        $result = $coupons->check($data['code'], (int) $data['subtotal'], $data['member_id'] ?? null);
        if ($result['coupon']) {
            $result['coupon'] = collect($result['coupon'])->only(['code', 'name', 'type', 'value', 'max_discount', 'min_purchase', 'ends_at', 'status'])->all();
        }

        return response()->json($result);
    }
}
