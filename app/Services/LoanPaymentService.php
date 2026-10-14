<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanPayment;
use App\Support\Rupiah;
use App\Support\Tanggal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Input angsuran manual: uang yang benar-benar diterima pengurus dialokasikan ke angsuran tertua yang belum lunas
 * (boleh sebagian; tidak boleh melebihi sisa pinjaman). Sisa & status pinjaman dihitung ulang dari jadwal:
 * semua lunas → lunas; ada angsuran lewat jatuh tempo → menunggak; selain itu berjalan.
 */
class LoanPaymentService
{
    public static function rules(Loan $loan): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) round((float) $loan->outstanding))],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.($loan->disbursed_at?->toDateString() ?? today()->toDateString())],
            'payment_method' => ['required', Rule::in(array_keys(Loan::METHODS))],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public static function messages(Loan $loan): array
    {
        return [
            'amount.required' => 'Isi nominal yang dibayar.',
            'amount.integer' => 'Nominal harus berupa angka.',
            'amount.min' => 'Nominal harus lebih dari 0.',
            'amount.max' => 'Nominal melebihi sisa pinjaman ('.Rupiah::format($loan->outstanding).').',
            'paid_at.required' => 'Isi tanggal bayar.',
            'paid_at.date_format' => 'Tanggal bayar tidak valid.',
            'paid_at.before_or_equal' => 'Tanggal bayar tidak boleh di masa depan.',
            'paid_at.after_or_equal' => 'Tanggal bayar tidak boleh sebelum tanggal cair ('.Tanggal::format($loan->disbursed_at).').',
            'payment_method.required' => 'Pilih metode bayar.',
            'payment_method.in' => 'Metode bayar tidak dikenal.',
            'note.max' => 'Catatan maksimal 200 karakter.',
        ];
    }

    /**
     * Rencana alokasi tanpa menyimpan (untuk pratinjau form).
     *
     * @param  Collection<int, LoanInstallment>  $installments  urut ke-n
     * @return list<array{installment_id: int, sequence: int, amount: int, settles: bool}>
     */
    public static function allocate(Collection $installments, int $amount): array
    {
        $plan = [];
        foreach ($installments as $i) {
            if ($amount <= 0) {
                break;
            }
            $remaining = (int) round((float) $i->amount - (float) $i->paid_amount);
            if ($i->status === 'dibayar' || $remaining <= 0) {
                continue;
            }
            $take = min($remaining, $amount);
            $plan[] = ['installment_id' => $i->id, 'sequence' => $i->sequence, 'amount' => $take, 'settles' => $take === $remaining];
            $amount -= $take;
        }

        return $plan;
    }

    /** @param  array  $data  data tervalidasi (rules()) */
    public function record(Loan $loan, array $data, ?int $userId = null): LoanPayment
    {
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;

        return DB::transaction(function () use ($loan, $data, $note, $userId) {
            $locked = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, Loan::ACTIVE, true)) {
                throw ValidationException::withMessages(['loan' => "Pinjaman {$locked->code} berstatus ".mb_strtolower(Loan::STATUSES[$locked->status] ?? $locked->status)
                    .'; angsuran hanya bisa dicatat untuk pinjaman yang berjalan atau menunggak.']);
            }
            $amount = (int) $data['amount'];
            $outstanding = (int) round((float) $locked->outstanding);
            if ($amount < 1 || $amount > $outstanding) {
                throw ValidationException::withMessages(['amount' => 'Nominal melebihi sisa pinjaman ('.Rupiah::format($outstanding).').']);
            }

            $installments = $locked->installments()->lockForUpdate()->get();
            $plan = self::allocate($installments, $amount);
            $byId = $installments->keyBy('id');
            foreach ($plan as $row) {
                $inst = $byId[$row['installment_id']];
                $paid = (int) round((float) $inst->paid_amount) + $row['amount'];
                $inst->update([
                    'paid_amount' => $paid, 'paid_at' => $data['paid_at'], 'payment_method' => $data['payment_method'], 'user_id' => $userId,
                    'status' => $row['settles'] ? 'dibayar' : $inst->status,
                ]);
            }

            $payment = LoanPayment::create(['loan_id' => $locked->id, 'paid_at' => $data['paid_at'], 'amount' => $amount, 'payment_method' => $data['payment_method'],
                'note' => $note, 'allocations' => array_map(fn ($r) => ['installment_id' => $r['installment_id'], 'sequence' => $r['sequence'], 'amount' => $r['amount']], $plan),
                'user_id' => $userId]);
            $before = $locked->status;
            $this->refreshLoan($locked);
            $locked->load('member');

            AuditLog::record('pinjaman.angsuran', 'pinjaman',
                "Angsuran {$locked->code} {$locked->member->name}: ".Rupiah::format($amount).' ('.(Loan::METHODS[$data['payment_method']] ?? $data['payment_method']).') '
                    .Tanggal::format($data['paid_at']).' untuk angsuran ke-'.implode(', ', array_column($plan, 'sequence')).' · sisa '.Rupiah::format($locked->outstanding),
                LoanApplicationService::subject($locked),
                ['payment_id' => $payment->id, 'allocations' => $payment->allocations, 'note' => $note, 'paid_at' => $data['paid_at'], 'payment_method' => $data['payment_method']],
                $amount, action: 'diubah',
                old: ['status' => $before, 'outstanding' => $outstanding],
                new: ['status' => $locked->status, 'outstanding' => (int) round((float) $locked->outstanding)]);

            return $payment;
        });
    }

    /**
     * Hitung ulang sisa, status, & tanggal lunas pinjaman dari jadwal (dipakai pembayaran, koreksi, deteksi tunggakan).
     * Status angsuran yang belum lunas: lewat jatuh tempo → menunggak, selain itu belum.
     */
    public function refreshLoan(Loan $loan): Loan
    {
        $today = today();
        $installments = $loan->installments()->get();
        foreach ($installments as $i) {
            if ($i->status === 'dibayar') {
                continue;
            }
            $should = $i->due_date->lt($today) ? 'menunggak' : 'belum';
            if ($i->status !== $should) {
                $i->update(['status' => $should]);
            }
        }
        $outstanding = (int) $installments->sum(fn ($i) => max(0, (int) round((float) $i->amount - (float) $i->paid_amount)));
        $status = $outstanding === 0 ? 'lunas' : ($installments->contains('status', 'menunggak') ? 'menunggak' : 'berjalan');
        $lastPaid = $installments->max(fn ($i) => $i->paid_at?->toDateString());

        $loan->update(['outstanding' => $outstanding, 'status' => $status,
            'paid_off_at' => $status === 'lunas' ? ($loan->paid_off_at ?? ($lastPaid ? \Illuminate\Support\Carbon::parse($lastPaid)->setTimeFrom(now()) : now())) : null]);

        return $loan;
    }
}
