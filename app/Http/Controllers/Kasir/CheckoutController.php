<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /kasir/transaksi
 * Body: { items: [{product_id, quantity}], payment_method: tunai|qris, paid?: int,
 *         discount?: {type: persen|nominal, value: int, reason?: string},  — diskon manual, dicek batasnya
 *         coupon?: string }                                                  — kode kupon/voucher
 * Menyimpan transaksi & mengurangi stok secara atomik (satu transaksi DB, baris barang dikunci);
 * total dihitung ulang di server. Header Idempotency-Key (atau client_ref) mencegah penjualan dobel:
 * permintaan ulang dengan kunci sama → 200 berisi transaksi yang sudah ada (replayed: true).
 */
class CheckoutController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'payment_method' => ['required', Rule::in(CheckoutService::CHECKOUT_METHODS)],
            'member_id' => ['nullable', 'integer', 'required_if:payment_method,tabungan,bon'],
            'credit_due_at' => ['nullable', 'date_format:Y-m-d', 'required_if:payment_method,bon'],
            'saving_type_id' => ['nullable', 'integer', 'required_if:payment_method,simpanan'],
            'credit_note' => ['nullable', 'string', 'max:100'],
            'paid' => ['nullable', 'integer', 'min:0', 'required_if:payment_method,tunai'],
            'discount' => ['nullable', 'array:type,value,reason'],
            'discount.type' => ['required_with:discount', Rule::in(['persen', 'nominal'])],
            'discount.value' => ['required_with:discount', 'integer', 'min:1', 'max:10000000'],
            'discount.reason' => ['nullable', 'string', 'max:100'],
            'coupon' => ['nullable', 'string', 'max:30'],
            'client_ref' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
        ]);

        $ref = $request->header('Idempotency-Key') ?: ($data['client_ref'] ?? null);
        if ($ref !== null && (mb_strlen($ref) > 64 || ! preg_match('/^[A-Za-z0-9_\-:.]+$/', $ref))) {
            return response()->json(['message' => 'Idempotency-Key tidak valid.', 'errors' => ['client_ref' => ['Kunci idempoten tidak valid.']]], 422);
        }
        $existing = $ref ? \App\Models\Transaction::where('client_ref', $ref)->exists() : false;

        $transaction = $checkout->checkout(
            collect($data['items'])->pluck('quantity', 'product_id')->all(),
            $data['payment_method'],
            $data['paid'] ?? null,
            $request->user()?->id,
            $data['member_id'] ?? null,
            $data['discount'] ?? null,
            $data['coupon'] ?? null,
            ($data['payment_method'] === 'bon') ? ['due_at' => $data['credit_due_at'] ?? null, 'note' => $data['credit_note'] ?? null] : null,
            $ref,
            $data['payment_method'] === 'simpanan' ? (int) $data['saving_type_id'] : null,
        );

        return response()->json(['data' => $transaction, 'replayed' => $existing], $existing ? 200 : 201);
    }
}
