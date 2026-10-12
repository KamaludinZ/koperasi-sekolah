<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\ReceiptPdfService;
use App\Services\ReceiptPresenter;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Detail transaksi untuk struk:
 * - GET /kasir/transaksi/{transaction}        → JSON data struk + identitas koperasi
 * - GET /kasir/transaksi/{transaction}/struk  → halaman struk siap cetak (cetak ulang)
 * - GET /kasir/transaksi/{transaction}/struk.pdf → struk PDF tersimpan (?unduh=1 untuk diunduh)
 */
class TransactionReceiptController extends Controller
{
    public function show(Transaction $transaction, ReceiptPresenter $presenter): JsonResponse
    {
        return response()->json([
            'data' => $presenter->present($transaction),
            'profile' => $presenter->profile(),
        ]);
    }

    public function print(Transaction $transaction, ReceiptPresenter $presenter): View
    {
        return view('kasir.receipt-print', [
            'tx' => $presenter->present($transaction),
            'profile' => $presenter->profile(),
            'autoPrint' => request()->boolean('cetak', true),
        ]);
    }

    public function pdf(Transaction $transaction, ReceiptPdfService $pdf): Response
    {
        $filename = "struk-{$transaction->invoice_number}.pdf";
        $disposition = request()->boolean('unduh') ? 'attachment' : 'inline';

        return response($pdf->contents($transaction), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
        ]);
    }
}
