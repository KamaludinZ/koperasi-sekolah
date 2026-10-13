<?php

namespace App\Http\Controllers\Transaksi;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Services\TransactionReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /transaksi/{transaction}/retur  (menu Transaksi)
 * {items: [{transaction_item_id, quantity}], reason: rusak|salah_ambil|kedaluwarsa|lainnya,
 *  condition: layak|rusak, refund_method: tunai|qris|tabungan|bon, note?}
 * → 201 {data: pengajuan RTR- berstatus menunggu}. Validasi bisnis (batas hari, sisa jumlah, cara
 * pengembalian, satu pengajuan menunggu) di TransactionReturnService → 422.
 */
class ReturnController extends Controller
{
    public function store(Request $request, Transaction $transaction, TransactionReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.transaction_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:10000'],
            'reason' => ['required', Rule::in(array_keys(TransactionReturn::RETURN_REASONS))],
            'condition' => ['required', Rule::in(['layak', 'rusak'])],
            'refund_method' => ['required', Rule::in(array_keys(TransactionReturn::REFUND_METHODS))],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['reason.required' => 'Pilih alasan retur.', 'items.required' => 'Isi barang yang diretur.']);

        $r = $returns->requestReturn(
            $transaction,
            collect($data['items'])->pluck('quantity', 'transaction_item_id')->map(fn ($q) => (int) $q)->all(),
            $data['reason'],
            $data['condition'] === 'layak',
            $data['refund_method'],
            $data['note'] ?? null,
            $request->user(),
        );

        return response()->json(['data' => TransactionReturnService::toArray($r)], 201);
    }
}
