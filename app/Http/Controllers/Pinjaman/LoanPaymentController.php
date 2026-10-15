<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\LoanPaymentService;
use App\Support\Rupiah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /pinjaman/{loan}/angsuran (Admin & Pengurus, JSON): simpan angsuran manual.
 * amount (rupiah bulat, 1..sisa pinjaman), paid_at (Y-m-d, antara tanggal cair & hari ini, bawaan hari ini),
 * payment_method (potong_gaji|transfer|tunai, bawaan pilihan peminjam), reference (wajib untuk transfer: no./berita;
 * potong gaji: bulan Y-m), note (opsional). Pembayaran dialokasikan ke angsuran terlama dulu; sisa & status pinjaman
 * dihitung ulang, dan perubahan tiap angsuran masuk riwayat.
 * Header Idempotency-Key (opsional, [A-Za-z0-9_-:.]{1,64}) mencegah pencatatan dobel: kiriman ulang dengan kunci sama
 * mengembalikan pembayaran yang sudah tercatat (200, replayed=true) tanpa mencatat lagi.
 *
 * POST /pinjaman/{loan}/hitung-ulang → hitung ulang sisa & status (berjalan/menunggak/lunas) dari pembayaran sah;
 * mengembalikan keadaan sebelum/sesudah dan jumlah angsuran yang diselaraskan.
 */
class LoanPaymentController extends Controller
{
    public function store(Request $request, Loan $loan, LoanPaymentService $payments): JsonResponse
    {
        $ref = $request->header('Idempotency-Key');
        if ($ref !== null && ! preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $ref)) {
            return response()->json(['message' => 'Idempotency-Key tidak valid.', 'errors' => ['client_ref' => ['Kunci idempoten tidak valid.']]], 422);
        }
        // kiriman ulang dijawab sebelum validasi: sisa pinjaman sudah berkurang oleh pembayaran pertama
        $payment = LoanPaymentService::replayed($loan, $ref);
        if (! $payment) {
            $request->mergeIfMissing(['paid_at' => today()->toDateString(), 'payment_method' => $loan->payment_method]);
            $data = $request->validate(LoanPaymentService::rules($loan), LoanPaymentService::messages($loan));
            $payment = $payments->record($loan, $data, $request->user()?->id, $ref);
        }

        $loan->refresh();
        $row = LoanPaymentService::paymentToArray($payment->fresh('user'));

        return response()->json([
            'data' => [
                'payment' => $row,
                'loan' => ['id' => $loan->id, 'code' => $loan->code, 'status' => $loan->status, 'status_label' => Loan::STATUSES[$loan->status] ?? $loan->status,
                    'outstanding' => (int) round((float) $loan->outstanding), 'paid_off_at' => $loan->paid_off_at?->toDateString()],
                'balance' => $payments->balance($loan),
            ],
            'replayed' => $payment->wasReplayed,
            'message' => $payment->wasReplayed
                ? 'Pembayaran ini sudah tercatat sebelumnya.'
                : 'Angsuran '.Rupiah::format($row['amount']).' untuk angsuran ke-'.implode(', ', $row['sequences']).' tercatat. '
                    .($loan->status === 'lunas' ? "Pinjaman {$loan->code} lunas." : 'Sisa '.Rupiah::format($loan->outstanding).'.'),
        ], $payment->wasReplayed ? 200 : 201);
    }

    public function recalculate(Request $request, Loan $loan, LoanPaymentService $payments): JsonResponse
    {
        $row = $payments->recalculate($loan, $request->user()?->id);
        if ($row['skipped'] ?? false) {
            return response()->json(['message' => 'Pinjaman yang belum cair, ditolak, atau dibatalkan tidak punya angsuran untuk dihitung ulang.',
                'errors' => ['loan' => ['Pinjaman belum cair.']]], 422);
        }

        return response()->json(['data' => $row + ['balance' => $payments->balance($loan->refresh())],
            'message' => $row['changed'] ? 'Sisa & status pinjaman diperbarui.' : 'Sisa & status pinjaman sudah sesuai.']);
    }
}
