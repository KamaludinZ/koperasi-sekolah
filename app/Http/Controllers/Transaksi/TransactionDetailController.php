<?php

namespace App\Http\Controllers\Transaksi;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\ReceiptPresenter;
use App\Services\TransactionHistory;
use Illuminate\Http\JsonResponse;

/**
 * GET /transaksi/{transaction}/rincian
 * Detail lengkap satu transaksi (JSON): item, pembayaran, kasir, anggota,
 * navigasi ke transaksi lebih baru/lama, dan tautan struk.
 */
class TransactionDetailController extends Controller
{
    public function __invoke(Transaction $transaction, TransactionHistory $history, ReceiptPresenter $receipt): JsonResponse
    {
        return response()->json([
            'data' => $history->detail($transaction),
            'profile' => $receipt->profile(),
        ]);
    }
}
