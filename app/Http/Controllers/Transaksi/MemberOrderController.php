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
 * - POST /pesanan/{order}/status {status, catatan?}   → majukan status (menunggu → diproses → siap → selesai)
 * - POST /pesanan/{order}/batal {alasan}              → batalkan (stok & saldo dikembalikan)
 */
class MemberOrderController extends Controller
{
    public function status(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Order::STATUSES))],
            'catatan' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['data' => OrderService::toArray($orders->transition($order, $data['status'], $request->user(), $data['catatan'] ?? null))]);
    }

    public function cancel(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:3', 'max:200']], ['alasan.required' => 'Tulis alasan pembatalan.']);
        $order = $orders->cancel($order, $data['alasan'], $request->user()->id, $request->user()->name);

        return response()->json(['data' => OrderService::toArray($order)]);
    }

    public function pay(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['metode' => ['required', Rule::in(['tunai', 'qris'])]], ['metode.in' => 'Pilih tunai atau QRIS.']);

        return response()->json(['data' => OrderService::toArray($orders->markPaid($order, $data['metode'], $request->user()))]);
    }
}
