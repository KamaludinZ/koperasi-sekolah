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
 * {items: [{product_id, quantity}] | dari_keranjang: true, fulfillment, delivery_to?, payment_method, saving_type_id?, note?}
 * Dengan `dari_keranjang`, isi diambil dari keranjang sesi (/portal/keranjang) dan dikosongkan setelah berhasil.
 * Wajib token PIN sekali pakai (header X-Pin-Token atau pin_token) dari POST /portal/pin/verifikasi.
 * Header Idempotency-Key mencegah pesanan dobel (kunci sama → pesanan yang sama, 200).
 */
class OrderController extends Controller
{
    /**
     * POST /portal/pesanan/{order}/bayar-saldo {simpanan_id?} + token PIN → lunasi pesanan yang belum dibayar
     * dengan potong saldo tabungan (tanpa simpanan_id) atau simpanan; tanpa persetujuan pengurus.
     */
    public function payWithBalance(Request $request, Order $order, OrderService $orders, PortalPinService $pins): JsonResponse
    {
        $data = $request->validate(['simpanan_id' => ['nullable', 'integer'], 'pin_token' => ['nullable', 'string', 'max:100']]);
        $member = Auth::guard('member')->user();
        abort_unless($order->member_id === $member->id, 404);
        if (! $pins->consumeToken($member, $request->header('X-Pin-Token') ?? ($data['pin_token'] ?? null))) {
            return response()->json(['message' => 'Verifikasi PIN dulu sebelum membayar.', 'errors' => ['pin' => ['PIN belum diverifikasi atau sudah kedaluwarsa.']]], 403);
        }
        $order = $orders->payWithBalance($order, $member, $data['simpanan_id'] ?? null);

        return response()->json(['data' => OrderService::toArray($order) + ['deduction' => OrderService::deductionFor($order)]]);
    }

    public function store(Request $request, OrderService $orders, PortalPinService $pins): JsonResponse
    {
        $fromCart = $request->boolean('dari_keranjang');
        $data = $request->validate([
            'dari_keranjang' => ['nullable', 'boolean'],
            'items' => [$fromCart ? 'prohibited' : 'required', 'array', 'min:1', 'max:'.OrderService::MAX_LINES],
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

        $cart = $fromCart ? \App\Support\PortalCart::items() : collect($data['items'])->pluck('quantity', 'product_id')->all();
        $order = $orders->place($member, $cart, $data, $ref);
        if ($fromCart) {
            \App\Support\PortalCart::clear(); // pesanan dibuat dari keranjang → keranjang dikosongkan
        }

        return response()->json(['data' => OrderService::toArray($order), 'replayed' => false], 201);
    }
}
