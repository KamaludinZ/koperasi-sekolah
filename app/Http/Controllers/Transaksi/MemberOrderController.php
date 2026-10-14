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
 * - GET  /pesanan/data?status=&q=&per_page=        → daftar (aktif dulu, terlama dulu) + jumlah per status
 * - GET  /pesanan/{order}/data                      → detail: anggota, barang, riwayat status, potong saldo, langkah berikutnya
 * - POST /pesanan/{order}/bayar {metode: tunai|qris} → catat pembayaran saat diambil/diantar (tunai masuk laci shift)
 * - POST /pesanan/{order}/status {status, catatan?}   → majukan status (menunggu → diproses → siap → selesai)
 * - POST /pesanan/{order}/batal {alasan}              → batalkan (stok & saldo dikembalikan)
 */
class MemberOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Order::STATUSES))],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = OrderService::staffQuery($data['status'] ?? '', $data['q'] ?? '')->paginate((int) ($data['per_page'] ?? 25))->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Order $o) => OrderService::toArray($o)
                + ['member' => ['id' => $o->member->id, 'code' => $o->member->member_code, 'name' => $o->member->name]])->values(),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'counts' => OrderService::statusCounts()],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => OrderService::detail($order)]);
    }

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
