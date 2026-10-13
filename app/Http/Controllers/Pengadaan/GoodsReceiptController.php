<?php

namespace App\Http\Controllers\Pengadaan;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penerimaan barang (JSON; menu Pengadaan, catat lewat gate kelola-pengadaan).
 * - GET  /pengadaan/penerimaan/data → penerimaan terbaru
 * - POST /pengadaan/pesanan/{order}/terima {received_at, delivery_note?, note?, items: {purchase_order_item_id: jumlah}}
 *        → 201 penerimaan; stok bertambah, status pesanan diperbarui, tagihan supplier dibuat
 */
class GoodsReceiptController extends Controller
{
    public function index(GoodsReceiptService $receipts): JsonResponse
    {
        return response()->json(['data' => $receipts->recent(25)]);
    }

    public function store(Request $request, PurchaseOrder $order, GoodsReceiptService $receipts): JsonResponse
    {
        $data = $request->validate(GoodsReceiptService::rules($order), GoodsReceiptService::messages());
        $receipt = $receipts->receive($order, $data['items'], $data, $request->user());

        return response()->json(['data' => [
            'id' => $receipt->id, 'number' => $receipt->number, 'total_quantity' => $receipt->total_quantity,
            'total_value' => $receipt->total_value, 'order_status' => $order->fresh()->status,
        ]], 201);
    }
}
