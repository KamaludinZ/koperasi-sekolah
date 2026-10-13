<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberCredit;
use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bon (piutang) anggota: batas, ringkasan, pemeriksaan sebelum bayar, dan pencatatan bon dari
 * transaksi kasir bermetode `bon`.
 * - Batas = members.credit_limit, atau bawaan per jenis anggota (config koperasi.credit.limits).
 * - Bon baru ditolak bila anggota nonaktif, masih punya bon lewat jatuh tempo, atau melebihi sisa batas.
 * - Jatuh tempo: besok s.d. config koperasi.credit.max_days hari.
 * Nomor bon BON-YYYYMMDD-NNNN berurutan per hari.
 */
class MemberCreditService
{
    public static function limitFor(Member $member): int
    {
        return $member->credit_limit ?? (int) (config('koperasi.credit.limits')[$member->type] ?? config('koperasi.credit.limits.siswa', 0));
    }

    public static function maxDays(): int
    {
        return (int) config('koperasi.credit.max_days', 60);
    }

    /**
     * Ringkasan bon anggota (bentuk sama dengan MockData::memberCredit).
     *
     * @return array{limit: int, outstanding: int, available: int, open_bills: int, oldest_due_at: ?string, overdue: bool}
     */
    public function summary(Member $member): array
    {
        $open = $member->credits()->open()->orderBy('due_at')->get(['id', 'amount', 'paid', 'due_at', 'status']);
        $limit = self::limitFor($member);
        $outstanding = (int) $open->sum(fn (MemberCredit $c) => $c->remaining());
        $oldest = $open->first()?->due_at;

        return [
            'limit' => $limit,
            'outstanding' => $outstanding,
            'available' => max(0, $limit - $outstanding),
            'open_bills' => $open->count(),
            'oldest_due_at' => $oldest?->toDateString(),
            'overdue' => $oldest !== null && $oldest->lt(today()),
        ];
    }

    /**
     * Pemeriksaan sebelum bayar (tanpa mengubah data).
     *
     * @return array{can_pay: bool, reason: string, amount: int, shortfall: int}&array<string, mixed>
     *                                                                                                 reason: ok | inactive | overdue | over_limit
     */
    public function check(Member $member, int $amount): array
    {
        $s = $this->summary($member);
        $short = max(0, $amount - $s['available']);
        $reason = match (true) {
            ! $member->is_active || $member->trashed() => 'inactive',
            $s['overdue'] => 'overdue',
            $short > 0 => 'over_limit',
            default => 'ok',
        };

        return ['can_pay' => $reason === 'ok', 'reason' => $reason, 'amount' => $amount, 'shortfall' => $short] + $s;
    }

    /** Tanggal jatuh tempo valid (Y-m-d) atau ValidationException `credit_due_at`. */
    public static function validDueDate(?string $dueAt): Carbon
    {
        $date = $dueAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAt) ? Carbon::parse($dueAt)->startOfDay() : null;
        if (! $date) {
            throw ValidationException::withMessages(['credit_due_at' => 'Pilih tanggal jatuh tempo bon.']);
        }
        if ($date->lte(today())) {
            throw ValidationException::withMessages(['credit_due_at' => 'Jatuh tempo paling cepat besok.']);
        }
        if ($date->gt(today()->addDays(self::maxDays()))) {
            throw ValidationException::withMessages(['credit_due_at' => 'Jatuh tempo paling lama '.self::maxDays().' hari dari hari ini.']);
        }

        return $date;
    }

    /**
     * Catat bon untuk transaksi kasir. Dipanggil di dalam transaksi DB checkout: gagal = penjualan batal.
     *
     * @throws ValidationException kunci `member_id` (anggota/batas/lewat jatuh tempo) atau `credit_due_at`
     */
    public function record(int $memberId, int $amount, Transaction $transaction, ?string $dueAt, ?string $note = null, ?int $userId = null): MemberCredit
    {
        $due = self::validDueDate($dueAt);

        return DB::transaction(function () use ($memberId, $amount, $transaction, $due, $note, $userId) {
            // Kunci baris anggota agar dua bon bersamaan tidak melewati batas.
            $member = Member::whereKey($memberId)->lockForUpdate()->first();
            if (! $member) {
                throw ValidationException::withMessages(['member_id' => 'Anggota tidak ditemukan.']);
            }
            $check = $this->check($member, $amount);
            if (! $check['can_pay']) {
                throw ValidationException::withMessages(['member_id' => match ($check['reason']) {
                    'inactive' => 'Anggota nonaktif tidak bisa berbelanja dengan bon.',
                    'overdue' => 'Anggota masih punya bon yang lewat jatuh tempo. Minta dilunasi dulu.',
                    default => 'Melebihi sisa batas bon sebesar Rp'.number_format($check['shortfall'], 0, ',', '.')
                        .' (sisa Rp'.number_format($check['available'], 0, ',', '.').').',
                }]);
            }

            $credit = $this->createWithNumber([
                'member_id' => $member->id,
                'transaction_id' => $transaction->id,
                'amount' => $amount,
                'due_at' => $due->toDateString(),
                'note' => filled($note) ? mb_substr(trim($note), 0, 100) : null,
                'created_by' => $userId,
            ]);

            AuditLog::record('credit.created', 'tabungan',
                "Bon {$credit->number} {$member->name}: Rp".number_format($amount, 0, ',', '.')." ({$transaction->invoice_number}), jatuh tempo ".$due->translatedFormat('d M Y'),
                ['type' => 'member', 'id' => $member->id, 'label' => "{$member->member_code} · {$member->name}"],
                ['credit_id' => $credit->id, 'transaction_id' => $transaction->id, 'due_at' => $due->toDateString(), 'outstanding_after' => $check['outstanding'] + $amount, 'limit' => $check['limit']],
                $amount);

            return $credit;
        });
    }

    /** @return array<string, mixed> */
    public static function toArray(MemberCredit $c): array
    {
        return [
            'id' => $c->id,
            'number' => $c->number,
            'member_id' => $c->member_id,
            'transaction_id' => $c->transaction_id,
            'invoice' => $c->transaction?->invoice_number,
            'amount' => $c->amount,
            'paid' => $c->paid,
            'remaining' => $c->remaining(),
            'due_at' => $c->due_at->toDateString(),
            'status' => $c->status,
            'status_label' => MemberCredit::STATUSES[$c->status] ?? $c->status,
            'overdue' => $c->isOverdue(),
            'note' => $c->note,
            'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }

    private function createWithNumber(array $attributes): MemberCredit
    {
        $prefix = 'BON-'.now()->format('Ymd').'-';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = MemberCredit::where('number', 'like', $prefix.'%')->max('number');
            $next = ($last ? (int) substr($last, strlen($prefix)) : 0) + 1 + $attempt;
            try {
                return DB::transaction(fn () => MemberCredit::create([...$attributes, 'number' => $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT)]));
            } catch (UniqueConstraintViolationException) {
                // nomor dipakai bon lain — ulangi
            }
        }

        throw new \RuntimeException('Gagal membuat nomor bon unik.');
    }
}
