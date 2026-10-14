<?php

namespace App\Http\Controllers\Transaksi;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pesanan portal anggota di sisi petugas (JSON, menu Transaksi):
 * - POST /pesanan/{order}/bayar {metode: tunai|qris} → catat pembayaran saat diambil/diantar (tunai masuk laci shift)
 */
class MemberOrderController extends Controller
{
    public function pay(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['metode' => ['required', Rule::in(['tunai', 'qris'])]], ['metode.in' => 'Pilih tunai atau QRIS.']);

        return response()->json(['data' => OrderService::toArray($orders->markPaid($order, $data['metode'], $request->user()))]);
    }
}
