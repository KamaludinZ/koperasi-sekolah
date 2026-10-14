<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PortalPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * POST /portal/pesanan (JSON, wajib masuk portal)
 * {items: [{product_id, quantity}], fulfillment, delivery_to?, payment_method, saving_type_id?, note?}
 * Wajib token PIN sekali pakai (header X-Pin-Token atau pin_token) dari POST /portal/pin/verifikasi.
 * Header Idempotency-Key mencegah pesanan dobel (kunci sama → pesanan yang sama, 200).
 */
class OrderController extends Controller
{
    public function store(Request $request, OrderService $orders, PortalPinService $pins): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.OrderService::MAX_LINES],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.OrderService::MAX_QTY],
            'fulfillment' => ['required', Rule::in(array_keys(Order::FULFILLMENTS))],
            'delivery_to' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['required', Rule::in(array_keys(Order::PAYMENTS))],
            'saving_type_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
            'pin_token' => ['nullable', 'string', 'max:100'],
        ]);
        $member = Auth::guard('member')->user();
        $ref = $request->header('Idempotency-Key');
        if ($ref !== null && ! preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $ref)) {
            return response()->json(['message' => 'Idempotency-Key tidak valid.', 'errors' => ['client_ref' => ['Kunci idempoten tidak valid.']]], 422);
        }
        if ($ref && ($existing = Order::where('client_ref', $ref)->where('member_id', $member->id)->first())) {
            return response()->json(['data' => OrderService::toArray($existing), 'replayed' => true]);
        }

        if (! $pins->consumeToken($member, $request->header('X-Pin-Token') ?? ($data['pin_token'] ?? null))) {
            return response()->json(['message' => 'Verifikasi PIN dulu sebelum mengirim pesanan.', 'errors' => ['pin' => ['PIN belum diverifikasi atau sudah kedaluwarsa.']]], 403);
        }

        $order = $orders->place($member, collect($data['items'])->pluck('quantity', 'product_id')->all(), $data, $ref);

        return response()->json(['data' => OrderService::toArray($order), 'replayed' => false], 201);
    }
}
