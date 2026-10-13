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
 *
 * POST /transaksi/{transaction}/batal  {reason: salah_input|batal_beli|ganda|lainnya, note?}
 * → 201 {data: pengajuan BTL-, auto_approved}. Pembatalan kecil oleh kasir yang sama langsung
 * `disetujui` (aturan nilai batas di pengaturan), selainnya `menunggu`.
 *
 * GET  /transaksi/retur/daftar?status=&jenis=&q=&saya=1 → {data, counts, stats}
 * GET  /transaksi/retur/menunggu         (gate setujui-retur) → {data: antrean terlama dulu}
 * GET  /transaksi/retur/{return}          → {data}
 * POST /transaksi/retur/{return}/setujui  {note?}  (gate setujui-retur; bukan pengaju) → {data}
 * POST /transaksi/retur/{return}/tolak    {note}   (gate setujui-retur; min. 5 karakter) → {data}
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

    public function void(Request $request, Transaction $transaction, TransactionReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(TransactionReturn::VOID_REASONS))],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['reason.required' => 'Pilih alasan pembatalan.']);

        $r = $returns->requestVoid($transaction, $data['reason'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => TransactionReturnService::toArray($r), 'auto_approved' => $r->status === 'disetujui'], 201);
    }

    public function index(Request $request, TransactionReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(TransactionReturn::STATUSES))],
            'jenis' => ['nullable', Rule::in(array_keys(TransactionReturn::KINDS))],
            'q' => ['nullable', 'string', 'max:60'],
            'saya' => ['nullable', 'boolean'],
        ]);
        $filters = ['kind' => $data['jenis'] ?? null, 'search' => $data['q'] ?? null, 'requested_by' => ($data['saya'] ?? false) ? $request->user()->id : null];

        return response()->json([
            'data' => $returns->list([...$filters, 'status' => $data['status'] ?? null]),
            'counts' => $returns->counts($filters),
            'stats' => $returns->stats(),
        ]);
    }

    public function queue(TransactionReturnService $returns): JsonResponse
    {
        return response()->json(['data' => $returns->queue()]);
    }

    public function show(TransactionReturn $return): JsonResponse
    {
        return response()->json(['data' => TransactionReturnService::toArray($return)]);
    }

    public function approve(Request $request, TransactionReturn $return, TransactionReturnService $returns): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        return response()->json(['data' => TransactionReturnService::toArray($returns->approve($return, $request->user(), $data['note'] ?? null))]);
    }

    public function reject(Request $request, TransactionReturn $return, TransactionReturnService $returns): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:200']], [
            'note.required' => 'Tulis alasan penolakan.', 'note.min' => 'Alasan penolakan minimal 5 karakter.',
        ]);

        return response()->json(['data' => TransactionReturnService::toArray($returns->reject($return, $request->user(), $data['note']))]);
    }
}
