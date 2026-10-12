<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /kasir/transaksi
 * Body: { items: [{product_id, quantity}], payment_method: tunai|qris, paid?: int }
 * Menyimpan transaksi & mengurangi stok; total dihitung ulang di server.
 */
class CheckoutController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'payment_method' => ['required', Rule::in(array_keys(CheckoutService::PAYMENT_METHODS))],
            'paid' => ['nullable', 'integer', 'min:0', 'required_if:payment_method,tunai'],
        ]);

        $transaction = $checkout->checkout(
            collect($data['items'])->pluck('quantity', 'product_id')->all(),
            $data['payment_method'],
            $data['paid'] ?? null,
            $request->user()?->id,
        );

        return response()->json(['data' => $transaction], 201);
    }
}
