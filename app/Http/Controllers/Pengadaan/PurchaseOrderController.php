<?php

namespace App\Http\Controllers\Pengadaan;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pesanan pembelian (JSON; menu Pengadaan, ubah lewat gate kelola-pengadaan).
 * - GET  /pengadaan/pesanan/data?status=berjalan|terlambat|draf|dipesan|sebagian|diterima|dibatalkan&supplier=&q=
 * - GET  /pengadaan/pesanan/{order}/data      → pesanan + barang + penerimaan
 * - POST /pengadaan/pesanan                    → {supplier_id, expected_at, note?, status: draf|dipesan, items: [{product_id, qty, cost}]} (201)
 * - POST /pengadaan/pesanan/{order}/kirim      → draf → dipesan
 * - POST /pengadaan/pesanan/{order}/batal      → {alasan} batalkan (hanya bila belum ada barang diterima)
 */
class PurchaseOrderController extends Controller
{
    public function index(Request $request, PurchaseOrderService $orders): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...array_keys(PurchaseOrder::STATUSES), 'berjalan', 'terlambat'])],
            'supplier' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $orders->list(['status' => $f['status'] ?? '', 'supplier' => $f['supplier'] ?? '', 'search' => $f['q'] ?? ''])]);
    }

    public function show(PurchaseOrder $order): JsonResponse
    {
        $order->load(['items', 'supplier', 'creator', 'receipts.items']);

        return response()->json(['data' => PurchaseOrderService::toArray($order) + [
            'lines' => PurchaseOrderService::lines($order),
            'receipts' => $order->receipts->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'received_at' => $r->received_at->toDateString(),
                'total_quantity' => $r->total_quantity, 'total_value' => $r->total_value])->all(),
        ]]);
    }

    public function store(Request $request, PurchaseOrderService $orders): JsonResponse
    {
        $data = $request->validate(PurchaseOrderService::rules(), PurchaseOrderService::messages());
        $order = $orders->create($data, $request->user());

        return response()->json(['data' => PurchaseOrderService::toArray($order) + ['lines' => PurchaseOrderService::lines($order)]], 201);
    }

    public function send(PurchaseOrder $order, PurchaseOrderService $orders): JsonResponse
    {
        return response()->json(['data' => PurchaseOrderService::toArray($orders->send($order))]);
    }

    public function cancel(Request $request, PurchaseOrder $order, PurchaseOrderService $orders): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'min:3', 'max:200']], ['alasan.required' => 'Tulis alasan pembatalan.']);

        return response()->json(['data' => PurchaseOrderService::toArray($orders->cancel($order, $data['alasan']))]);
    }
}
