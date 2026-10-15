<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanInstallmentChange;
use App\Models\LoanPayment;
use App\Services\LoanPaymentService;
use App\Support\Rupiah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /pinjaman/angsuran/pembayaran/{payment}/koreksi (Admin & Pengurus, JSON): koreksi pembayaran angsuran berjejak.
 * mode = ganti (bawaan; wajib amount, paid_at, payment_method, reference sesuai metode, note opsional) atau batal;
 * alasan wajib 5–200 karakter. Pembayaran lama tidak dihapus — ditandai batal dengan alasan & petugas, pengganti
 * merujuk ke pembayaran lama, alokasi diputar ulang, dan selisih tiap angsuran masuk riwayat perubahan (event koreksi).
 *
 * GET /pinjaman/{loan}/angsuran/jejak?event=&ke= → jejak perubahan angsuran pinjaman (terbaru dulu): kolom
 * sebelum/sesudah, jenis peristiwa, pembayaran terkait, alasan, petugas, waktu.
 */
class LoanPaymentCorrectionController extends Controller
{
    public function store(Request $request, LoanPayment $payment, LoanPaymentService $payments): JsonResponse
    {
        $loan = $payment->loan()->firstOrFail();
        $request->mergeIfMissing(['mode' => 'ganti']);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['ganti', 'batal'])],
            'alasan' => ['required', 'string', 'min:5', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
            'amount' => ['exclude_if:mode,batal', 'required', 'integer', 'min:1'],
            'paid_at' => ['exclude_if:mode,batal', 'required', 'date_format:Y-m-d'],
            'payment_method' => ['exclude_if:mode,batal', 'required', Rule::in(array_keys(Loan::METHODS))],
            'reference' => ['exclude_if:mode,batal', 'nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'note' => ['exclude_if:mode,batal', 'nullable', 'string', 'max:200', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ], [
            'mode.in' => 'Mode koreksi harus ganti atau batal.',
            'alasan.required' => 'Tulis alasan koreksi.', 'alasan.min' => 'Tulis alasan koreksi (5–200 karakter).', 'alasan.max' => 'Tulis alasan koreksi (5–200 karakter).',
            'alasan.not_regex' => 'Alasan berisi karakter yang tidak diizinkan.',
        ] + LoanPaymentService::messages($loan));

        $replacement = $data['mode'] === 'ganti' ? array_intersect_key($data, array_flip(['amount', 'paid_at', 'payment_method', 'reference', 'note'])) : null;
        $new = $payments->correct($payment, $data['alasan'], $replacement, $request->user()?->id);
        $loan->refresh();

        return response()->json([
            'data' => [
                'voided' => LoanPaymentService::paymentToArray($payment->fresh('user')),
                'replacement' => $new ? LoanPaymentService::paymentToArray($new->load('user')) : null,
                'loan' => ['id' => $loan->id, 'code' => $loan->code, 'status' => $loan->status, 'outstanding' => (int) round((float) $loan->outstanding)],
                'balance' => $payments->balance($loan),
                'changes' => LoanInstallmentChange::where('loan_payment_id', $payment->id)->where('event', 'koreksi')->orderBy('id')->get()->map(fn ($c) => self::changeToArray($c))->all(),
            ],
            'message' => ($new ? 'Pembayaran dikoreksi menjadi '.Rupiah::format($new->amount).'.' : 'Pembayaran dibatalkan.').' Sisa '.Rupiah::format($loan->outstanding).'.',
        ]);
    }

    public function trail(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'event' => ['nullable', Rule::in(array_keys(LoanInstallmentChange::EVENTS))],
            'ke' => ['nullable', 'integer', 'min:1', 'max:'.max(1, $loan->tenor_months)],
        ], ['event.in' => 'Jenis peristiwa tidak dikenal.', 'ke.max' => 'Angsuran ke- melebihi tenor.']);

        $rows = $loan->installmentChanges()->reorder()->orderByDesc('id')->with('payment:id,amount,paid_at,payment_method,reference,voided_at')
            ->when($data['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->when($data['ke'] ?? null, fn ($q, $n) => $q->where('sequence', $n))
            ->limit(500)->get();

        return response()->json([
            'data' => $rows->map(fn ($c) => self::changeToArray($c))->all(),
            'meta' => ['loan' => ['id' => $loan->id, 'code' => $loan->code, 'status' => $loan->status], 'count' => $rows->count(), 'events' => LoanInstallmentChange::EVENTS],
        ]);
    }

    public static function changeToArray(LoanInstallmentChange $c): array
    {
        return [
            'id' => $c->id, 'sequence' => $c->sequence, 'event' => $c->event, 'event_label' => LoanInstallmentChange::EVENTS[$c->event] ?? $c->event,
            'changes' => $c->changes, 'reason' => $c->reason, 'actor' => $c->actor_name, 'created_at' => $c->created_at?->toDateTimeString(),
            'payment' => $c->loan_payment_id ? ['id' => $c->loan_payment_id, 'amount' => $c->payment ? (int) round((float) $c->payment->amount) : null,
                'paid_at' => $c->payment?->paid_at?->toDateString(), 'voided' => $c->payment?->voided_at !== null] : null,
        ];
    }
}
