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
            // Rujukan wajib per metode: transfer → no./berita transfer; potong gaji → bulan gaji (Y-m); tunai → tidak perlu.
            'reference' => ['nullable', 'string', 'max:100'],
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
            'reference.max' => 'Rujukan maksimal 100 karakter.',
        ];
    }

    /** Label isian rujukan per metode (null = tidak perlu rujukan). */
    public const REFERENCE_LABELS = ['transfer' => 'No./berita transfer', 'potong_gaji' => 'Bulan gaji yang dipotong', 'tunai' => null];

    /**
     * Rujukan wajib & format per metode: transfer → minimal 3 karakter; potong gaji → bulan gaji Y-m (tidak lebih dari
     * bulan depan); tunai → dikosongkan. Galat dilempar dengan kunci `reference`.
     */
    public static function normalizeReference(string $method, ?string $reference): ?string
    {
        $reference = filled($reference) ? trim($reference) : null;

        return match ($method) {
            'transfer' => mb_strlen((string) $reference) >= 3 ? $reference
                : throw ValidationException::withMessages(['reference' => 'Isi nomor atau berita transfer (minimal 3 karakter).']),
            'potong_gaji' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $reference) && $reference <= today()->addMonthNoOverflow()->format('Y-m')
                ? $reference : throw ValidationException::withMessages(['reference' => 'Pilih bulan gaji yang dipotong.']),
            default => null,
        };
    }

    /** Teks rujukan untuk tampilan (bulan gaji → "Gaji Oktober 2026"). */
    public static function referenceLabel(?string $method, ?string $reference): ?string
    {
        if ($method === 'potong_gaji' && $reference && preg_match('/^\d{4}-\d{2}$/', $reference)) {
            return 'Gaji '.\Illuminate\Support\Carbon::parse($reference.'-01')->locale('id')->translatedFormat('F Y');
        }

        return $reference;
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
        $reference = self::normalizeReference($data['payment_method'], $data['reference'] ?? null);

        return DB::transaction(function () use ($loan, $data, $note, $reference, $userId) {
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
                'reference' => $reference, 'note' => $note, 'allocations' => array_map(fn ($r) => ['installment_id' => $r['installment_id'], 'sequence' => $r['sequence'], 'amount' => $r['amount']], $plan),
                'user_id' => $userId]);
            $before = $locked->status;
            $this->refreshLoan($locked);
            $locked->load('member');

            AuditLog::record('pinjaman.angsuran', 'pinjaman',
                "Angsuran {$locked->code} {$locked->member->name}: ".Rupiah::format($amount).' ('.(Loan::METHODS[$data['payment_method']] ?? $data['payment_method'])
                    .($reference ? ' · '.self::referenceLabel($data['payment_method'], $reference) : '').') '
                    .Tanggal::format($data['paid_at']).' untuk angsuran ke-'.implode(', ', array_column($plan, 'sequence')).' · sisa '.Rupiah::format($locked->outstanding),
                LoanApplicationService::subject($locked),
                ['payment_id' => $payment->id, 'allocations' => $payment->allocations, 'note' => $note, 'reference' => $reference, 'paid_at' => $data['paid_at'], 'payment_method' => $data['payment_method']],
                $amount, action: 'diubah',
                old: ['status' => $before, 'outstanding' => $outstanding],
                new: ['status' => $locked->status, 'outstanding' => (int) round((float) $locked->outstanding)]);

            return $payment;
        });
    }

    /**
     * Koreksi pembayaran: pembayaran lama ditandai batal (tidak dihapus) dengan alasan; bila $replacement diisi,
     * dicatat pembayaran pengganti (replaces_id). Lalu semua pembayaran sah diputar ulang ke jadwal dari awal
     * (urut tanggal bayar & urutan catat) sehingga alokasi, sisa, dan status pinjaman konsisten.
     *
     * @param  array{amount: int, paid_at: string, payment_method: string, reference?: ?string, note?: ?string}|null  $replacement
     */
    public function correct(LoanPayment $payment, string $reason, ?array $replacement = null, ?int $userId = null): ?LoanPayment
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan koreksi (5–200 karakter).']);
        }
        $reference = $replacement ? self::normalizeReference($replacement['payment_method'], $replacement['reference'] ?? null) : null;

        return DB::transaction(function () use ($payment, $reason, $replacement, $reference, $userId) {
            $loan = Loan::whereKey($payment->loan_id)->lockForUpdate()->firstOrFail();
            $locked = LoanPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at) {
                throw ValidationException::withMessages(['payment' => 'Pembayaran ini sudah dikoreksi sebelumnya; muat ulang halaman.']);
            }
            if (! in_array($loan->status, [...Loan::ACTIVE, 'lunas'], true)) {
                throw ValidationException::withMessages(['payment' => "Pinjaman {$loan->code} tidak bisa dikoreksi pada status ".mb_strtolower(Loan::STATUSES[$loan->status] ?? $loan->status).'.']);
            }
            $before = ['status' => $loan->status, 'outstanding' => (int) round((float) $loan->outstanding)];
            $old = self::paymentToArray($locked);

            $new = null;
            if ($replacement) {
                $others = (int) round((float) $loan->payments()->whereNull('voided_at')->whereKeyNot($locked->id)->sum('amount'));
                $max = $loan->totalDue() - $others;
                $amount = (int) $replacement['amount'];
                if ($amount < 1 || $amount > $max) {
                    throw ValidationException::withMessages(['amount' => 'Nominal pengganti harus 1–'.Rupiah::format($max).' (sisa setelah pembayaran lain).']);
                }
                if ($replacement['paid_at'] > today()->toDateString() || ($loan->disbursed_at && $replacement['paid_at'] < $loan->disbursed_at->toDateString())) {
                    throw ValidationException::withMessages(['paid_at' => 'Tanggal bayar harus antara tanggal cair dan hari ini.']);
                }
            }

            $locked->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);
            if ($replacement) {
                $new = LoanPayment::create(['loan_id' => $loan->id, 'paid_at' => $replacement['paid_at'], 'amount' => (int) $replacement['amount'],
                    'payment_method' => $replacement['payment_method'], 'reference' => $reference,
                    'note' => filled($replacement['note'] ?? null) ? trim($replacement['note']) : null,
                    'allocations' => [], 'user_id' => $userId, 'replaces_id' => $locked->id]);
            }

            $this->replay($loan);
            $this->refreshLoan($loan);
            $loan->load('member');

            AuditLog::record('pinjaman.angsuran_koreksi', 'pinjaman',
                "Koreksi angsuran {$loan->code} {$loan->member->name}: ".Rupiah::format($old['amount']).' '.Tanggal::format($old['paid_at'])
                    .($new ? ' → '.Rupiah::format($new->amount).' '.Tanggal::format($new->paid_at) : ' dibatalkan').' · '.$reason,
                LoanApplicationService::subject($loan),
                ['payment_id' => $locked->id, 'replacement_id' => $new?->id, 'old_payment' => $old, 'new_payment' => $new ? self::paymentToArray($new->fresh()) : null],
                $new ? (int) $new->amount - $old['amount'] : -$old['amount'], action: 'diubah',
                old: $before + ['payment' => ['amount' => $old['amount'], 'paid_at' => $old['paid_at'], 'payment_method' => $old['payment_method']]],
                new: ['status' => $loan->status, 'outstanding' => (int) round((float) $loan->outstanding),
                    'payment' => $new ? ['amount' => (int) $new->amount, 'paid_at' => $new->paid_at->toDateString(), 'payment_method' => $new->payment_method] : null],
                reason: $reason);

            return $new?->fresh();
        });
    }

    /** Putar ulang semua pembayaran sah ke jadwal yang dikosongkan; alokasi tiap pembayaran ikut diperbarui. */
    private function replay(Loan $loan): void
    {
        $installments = $loan->installments()->lockForUpdate()->get();
        foreach ($installments as $i) {
            $i->forceFill(['paid_amount' => 0, 'paid_at' => null, 'payment_method' => null, 'status' => 'belum'])->save();
        }
        $byId = $installments->keyBy('id');
        foreach ($loan->payments()->whereNull('voided_at')->reorder()->orderBy('paid_at')->orderBy('id')->get() as $payment) {
            $plan = self::allocate($installments, (int) round((float) $payment->amount));
            foreach ($plan as $row) {
                $inst = $byId[$row['installment_id']];
                $inst->forceFill(['paid_amount' => (int) round((float) $inst->paid_amount) + $row['amount'], 'paid_at' => $payment->paid_at,
                    'payment_method' => $payment->payment_method, 'user_id' => $payment->user_id, 'status' => $row['settles'] ? 'dibayar' : 'belum'])->save();
            }
            $payment->update(['allocations' => array_map(fn ($r) => ['installment_id' => $r['installment_id'], 'sequence' => $r['sequence'], 'amount' => $r['amount']], $plan)]);
        }
    }

    public static function paymentToArray(LoanPayment $p): array
    {
        return [
            'id' => $p->id, 'paid_at' => $p->paid_at->toDateString(), 'amount' => (int) round((float) $p->amount),
            'payment_method' => $p->payment_method, 'payment_label' => Loan::METHODS[$p->payment_method] ?? $p->payment_method,
            'reference' => $p->reference, 'reference_label' => self::referenceLabel($p->payment_method, $p->reference), 'note' => $p->note,
            'sequences' => array_column($p->allocations ?? [], 'sequence'), 'allocations' => $p->allocations ?? [],
            'user' => $p->user?->name, 'recorded_at' => $p->created_at?->toDateTimeString(),
            'voided' => $p->voided_at !== null, 'void_reason' => $p->void_reason, 'voided_at' => $p->voided_at?->toDateTimeString(),
            'replaces_id' => $p->replaces_id,
        ];
    }

    /**
     * Riwayat pembayaran satu pinjaman berurutan (tanggal bayar, lalu urutan catat) dengan saldo sisa berjalan:
     * sisa setelah tiap pembayaran = total pinjaman − jumlah pembayaran sah sampai baris itu. Pembayaran yang
     * dikoreksi tetap tampil (dicoret) dan tidak mengurangi sisa.
     *
     * @return list<array>
     */
    public function history(Loan $loan): array
    {
        $balance = $loan->totalDue();
        $n = 0;

        return $loan->payments()->with('user:id,name')->get()->map(function (LoanPayment $p) use (&$balance, &$n) {
            $row = self::paymentToArray($p);
            if (! $row['voided']) {
                $balance -= $row['amount'];
                $row['number'] = ++$n;
            } else {
                $row['number'] = null;
            }
            $row['balance_after'] = $balance;

            return $row;
        })->all();
    }

    /**
     * Ringkasan bayar & sisa satu pinjaman: total dibayar/sisa, dipecah porsi pokok & bunga (porsi per angsuran dari
     * generator jadwal, dibagi proporsional terhadap yang sudah dibayar), jumlah & pembayaran terakhir yang sah.
     */
    public function balance(Loan $loan): array
    {
        $principal = (int) round((float) $loan->principal);
        $interest = (int) round((float) $loan->interest);
        $total = $principal + $interest;
        $installments = $loan->installments()->get();
        $paid = (int) $installments->sum(fn ($i) => (int) round((float) $i->paid_amount));

        $interestPaid = 0;
        if ($loan->disbursed_at && $installments->isNotEmpty()) {
            $parts = collect(LoanScheduleGenerator::generate($principal, $interest, $loan->tenor_months, $loan->disbursed_at))->keyBy('sequence');
            foreach ($installments as $i) {
                $amount = (int) round((float) $i->amount);
                $part = $parts[$i->sequence]['interest_part'] ?? 0;
                $interestPaid += $amount > 0 ? intdiv($part * (int) round((float) $i->paid_amount), $amount) : 0;
            }
        }
        $valid = $loan->payments()->whereNull('voided_at');
        $last = (clone $valid)->reorder()->orderByDesc('paid_at')->orderByDesc('id')->first();

        return [
            'total' => $total, 'paid' => $paid, 'remaining' => $total - $paid,
            'principal' => $principal, 'principal_paid' => $paid - $interestPaid, 'principal_remaining' => $principal - ($paid - $interestPaid),
            'interest' => $interest, 'interest_paid' => $interestPaid, 'interest_remaining' => $interest - $interestPaid,
            'payments_count' => (clone $valid)->count(),
            'last_payment' => $last ? ['paid_at' => $last->paid_at->toDateString(), 'amount' => (int) round((float) $last->amount),
                'payment_label' => Loan::METHODS[$last->payment_method] ?? $last->payment_method] : null,
            'installments_paid' => $installments->where('status', 'dibayar')->count(), 'installments' => $installments->count(),
            'progress' => $total > 0 ? min(100, intdiv($paid * 100, $total)) : 0,
        ];
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
