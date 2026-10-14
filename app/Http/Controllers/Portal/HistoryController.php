<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Riwayat milik anggota yang masuk portal (JSON):
 * - GET /portal/riwayat/data?periode=&limit=   → transaksi kasir (terbaru dulu) + status batal/retur + total belanja
 * - GET /portal/pesanan/data?status=&limit=    → pesanan checkout mandiri
 * - GET /portal/pesanan/{order}/data           → detail pesanan: barang, riwayat status, bukti potong saldo
 */
class HistoryController extends Controller
{
    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', Rule::in(['30-hari', '90-hari', 'tahun-ini', 'semua'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $from = match ($data['periode'] ?? '90-hari') {
            '30-hari' => now()->subDays(29)->startOfDay(), '90-hari' => now()->subDays(89)->startOfDay(),
            'tahun-ini' => now()->startOfYear(), default => null,
        };
        $query = Transaction::with('items')->where('member_id', Auth::guard('member')->id())->when($from, fn ($q) => $q->where('created_at', '>=', $from));
        $spent = (clone $query)->whereNull('voided_at')->toBase()->selectRaw('COALESCE(SUM(total), 0) - COALESCE(SUM(returned_amount), 0) AS s, COUNT(*) AS n')->first();

        return response()->json([
            'data' => $query->latest('created_at')->latest('id')->limit((int) ($data['limit'] ?? 50))->get()->map(fn (Transaction $t) => [
                'id' => $t->id, 'invoice' => $t->invoice_number, 'at' => $t->created_at->toIso8601String(), 'total' => (int) round((float) $t->total),
                'payment_method' => $t->payment_method, 'payment_label' => CheckoutService::PAYMENT_LABELS[$t->payment_method] ?? $t->payment_method,
                'voided' => $t->voided_at !== null, 'returned_amount' => (int) round((float) $t->returned_amount),
                'items' => $t->items->map(fn ($i) => ['name' => $i->product_name, 'quantity' => (int) $i->quantity, 'price' => (int) round((float) $i->price),
                    'subtotal' => (int) round((float) $i->subtotal)])->all(),
            ])->values(),
            'meta' => ['count' => (int) $spent->n, 'spent' => (int) round((float) $spent->s)],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(array_keys(Order::STATUSES))], 'limit' => ['nullable', 'integer', 'min:1', 'max:200']]);

        return response()->json(['data' => Order::with('savingType:id,name')->where('member_id', Auth::guard('member')->id())
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('created_at')->latest('id')->limit((int) ($data['limit'] ?? 50))->get()
            ->map(fn (Order $o) => array_merge(OrderService::toArray($o), ['created_at' => $o->created_at->toIso8601String()]))->values()]);
    }

    public function order(Order $order): JsonResponse
    {
        abort_unless($order->member_id === Auth::guard('member')->id(), 404);
        $order->load(['items', 'statusLogs', 'savingType:id,name']);

        return response()->json(['data' => array_merge(OrderService::toArray($order), [
            'created_at' => $order->created_at->toIso8601String(),
            'items' => $order->items->map(fn ($i) => ['name' => $i->product_name, 'quantity' => $i->quantity, 'price' => (int) round((float) $i->price),
                'subtotal' => (int) round((float) $i->subtotal)])->all(),
            'subtotal' => (int) round((float) $order->subtotal), 'tax' => (int) round((float) $order->tax), 'rounding' => (int) round((float) $order->rounding),
            'status_logs' => $order->statusLogs->map(fn ($l) => ['from' => $l->from_status, 'to' => $l->to_status, 'note' => $l->note,
                'actor' => $l->actor_name, 'at' => $l->created_at->toIso8601String()])->all(),
            'deduction' => ($d = OrderService::deductionFor($order)) ? ['source' => $d['source'], 'amount' => $d['amount'], 'before' => $d['before'], 'after' => $d['after'],
                'refunded' => $d['refund']['amount'] ?? 0] : null,
        ])]);
    }
}
