<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\MemberCredit;
use App\Models\MemberCreditPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bon (piutang) anggota: batas, ringkasan, pemeriksaan sebelum bayar, dan pencatatan bon dari
 * transaksi kasir bermetode `bon`.
 * - Pengaturan bagian `credit` (SettingsService): aktif/tidak, batas bawaan siswa/guru, jatuh tempo
 *   bawaan & terlama, blokir bila ada bon lewat jatuh tempo.
 * - Batas = members.credit_limit (khusus anggota), atau batas bawaan jenis anggotanya.
 * - Bon baru ditolak bila bon dimatikan, anggota nonaktif, (opsional) masih punya bon lewat jatuh
 *   tempo, atau melebihi sisa batas. Jatuh tempo: besok s.d. max_days hari.
 * Nomor bon BON-YYYYMMDD-NNNN berurutan per hari.
 * Pelunasan (repay): tunai atau potong tabungan, boleh sebagian, dibagi ke bon jatuh tempo terlama
 * dulu; pelunasan tunai masuk laci shift yang sedang buka (kas masuk). Nomor PLN-YYYYMMDD-NNNN.
 */
class MemberCreditService
{
    public static function settings(): array
    {
        return app(SettingsService::class)->credit();
    }

    public static function limitFor(Member $member): int
    {
        $s = self::settings();

        return $member->credit_limit ?? (int) ($s['limit_'.$member->type] ?? $s['limit_siswa']);
    }

    public static function enabled(): bool
    {
        return (bool) self::settings()['enabled'];
    }

    public static function maxDays(): int
    {
        return (int) self::settings()['max_days'];
    }

    public static function defaultDays(): int
    {
        return min((int) self::settings()['default_days'], self::maxDays());
    }

    /**
     * Ringkasan bon anggota.
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
     *                                                                                                 reason: ok | disabled | inactive | overdue | over_limit
     */
    public function check(Member $member, int $amount): array
    {
        $s = $this->summary($member);
        $short = max(0, $amount - $s['available']);
        $reason = match (true) {
            ! self::enabled() => 'disabled',
            ! $member->is_active || $member->trashed() => 'inactive',
            $s['overdue'] && self::settings()['block_overdue'] => 'overdue',
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
                    'disabled' => 'Pembayaran bon sedang dimatikan di pengaturan.',
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

    /**
     * Lunasi bon anggota (sebagian atau semua).
     *
     * @param  string  $method  tunai | tabungan
     *
     * @throws ValidationException kunci `amount` / `method`
     */
    public function repay(int $memberId, int $amount, string $method, ?string $note = null, ?User $user = null): MemberCreditPayment
    {
        if (! array_key_exists($method, MemberCreditPayment::METHODS)) {
            throw ValidationException::withMessages(['method' => 'Cara pelunasan tidak dikenal.']);
        }

        return DB::transaction(function () use ($memberId, $amount, $method, $note, $user) {
            $member = Member::withTrashed()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $open = $member->credits()->open()->orderBy('due_at')->orderBy('id')->lockForUpdate()->get();
            $outstanding = (int) $open->sum(fn (MemberCredit $c) => $c->remaining());
            if ($outstanding === 0) {
                throw ValidationException::withMessages(['amount' => 'Tidak ada bon yang belum lunas.']);
            }
            $min = min(500, $outstanding);
            if ($amount < $min || $amount > $outstanding) {
                throw ValidationException::withMessages(['amount' => $amount < $min
                    ? 'Pelunasan minimal Rp'.number_format($min, 0, ',', '.').'.'
                    : 'Melebihi sisa bon Rp'.number_format($outstanding, 0, ',', '.').'.']);
            }

            $payment = $this->createPaymentWithNumber([
                'member_id' => $member->id,
                'amount' => $amount,
                'method' => $method,
                'note' => filled($note) ? mb_substr(trim($note), 0, 150) : null,
                'paid_by' => $user?->id,
                'paid_at' => now(),
            ]);

            $left = $amount;
            foreach ($open as $credit) {
                if ($left <= 0) {
                    break;
                }
                $part = min($left, $credit->remaining());
                $payment->allocations()->create(['member_credit_id' => $credit->id, 'amount' => $part]);
                $credit->syncPaid();
                $left -= $part;
            }

            if ($method === 'tabungan') {
                $entry = app(SavingsPaymentService::class)->debitForCredit($member->id, $amount, $payment->id, "Pelunasan bon {$payment->number}", $user?->id);
                $payment->forceFill(['savings_transaction_id' => $entry->id])->save();
            } elseif ($shift = CashShift::open()->lockForUpdate()->first()) {
                // Uang pelunasan tunai diterima di kasir → masuk laci shift yang sedang buka.
                $shift->movements()->create([
                    'type' => CashMovement::TYPE_IN, 'category' => 'lainnya', 'amount' => $amount,
                    'reason' => "Pelunasan bon {$payment->number} · {$member->name}", 'user_id' => $user?->id,
                ]);
            }

            $settled = $open->filter(fn (MemberCredit $c) => $c->status === 'lunas')->count();
            AuditLog::record('credit.payment', 'tabungan',
                "Pelunasan bon {$member->name} {$payment->number}: Rp".number_format($amount, 0, ',', '.').' ('.mb_strtolower(MemberCreditPayment::METHODS[$method]).')'
                    .($settled ? ", {$settled} bon lunas" : ''),
                ['type' => 'member', 'id' => $member->id, 'label' => "{$member->member_code} · {$member->name}"],
                ['payment_id' => $payment->id, 'method' => $method, 'allocations' => $payment->allocations()->pluck('amount', 'member_credit_id')->all(),
                    'outstanding_after' => $outstanding - $amount],
                $amount);

            return $payment->load('allocations.credit');
        });
    }

    /** Status piutang anggota dari jatuh tempo bon tertua: lewat | minggu (≤ 7 hari) | berjalan. */
    public static function statusFor(?string $oldestDueAt): string
    {
        $due = $oldestDueAt ? Carbon::parse($oldestDueAt) : null;

        return match (true) {
            $due === null => 'berjalan',
            $due->lt(today()) => 'lewat',
            $due->lte(today()->addDays(7)) => 'minggu',
            default => 'berjalan',
        };
    }

    /**
     * Anggota yang masih punya bon (termasuk anggota nonaktif), lewat jatuh tempo & terdekat dulu.
     *
     * @param  array{search?: ?string, type?: ?string, status?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    public function debtors(array $filters = []): array
    {
        $agg = MemberCredit::open()
            ->selectRaw('member_id, SUM(amount - paid) as outstanding, COUNT(*) as bills, MIN(due_at) as oldest_due_at')
            ->groupBy('member_id');

        $rows = Member::withTrashed()
            ->joinSub($agg, 'c', 'c.member_id', '=', 'members.id')
            ->search($filters['search'] ?? null)
            ->ofType(($filters['type'] ?? '') ?: null)
            ->orderBy('c.oldest_due_at')->orderByRaw('LOWER(members.name)')
            ->get(['members.*', 'c.outstanding', 'c.bills', 'c.oldest_due_at'])
            ->map(function (Member $m) {
                $oldest = Carbon::parse($m->getAttribute('oldest_due_at'))->toDateString();

                return MemberService::toArray($m) + [
                    'bills' => (int) $m->getAttribute('bills'),
                    'outstanding' => (int) $m->getAttribute('outstanding'),
                    'limit' => self::limitFor($m),
                    'oldest_due_at' => $oldest,
                    'status' => self::statusFor($oldest),
                ];
            });

        $rank = ['lewat' => 0, 'minggu' => 1, 'berjalan' => 2];
        $status = $filters['status'] ?? '';

        return $rows->filter(fn ($r) => $status === '' || $r['status'] === $status)
            ->sortBy(fn ($r) => [$rank[$r['status']], $r['oldest_due_at'], mb_strtolower($r['name'])])
            ->values()->all();
    }

    /** Ringkasan seluruh piutang. */
    public function stats(): array
    {
        $d = collect($this->debtors());
        $late = $d->where('status', 'lewat');

        return [
            'total' => (int) $d->sum('outstanding'),
            'members' => $d->count(),
            'late_total' => (int) $late->sum('outstanding'),
            'late_members' => $late->count(),
            'week_members' => $d->where('status', 'minggu')->count(),
        ];
    }

    /**
     * Riwayat bon baru & pelunasan seluruh anggota, terbaru dulu.
     *
     * @param  array{from?: ?Carbon, to?: ?Carbon, kind?: ?string, search?: ?string, type?: ?string, member_id?: ?int}  $filters
     * @return array{rows: list<array<string, mixed>>, bon: int, paid: int}
     */
    public function history(array $filters = [], int $limit = 500): array
    {
        $kind = $filters['kind'] ?? '';
        $memberScope = fn ($q) => $q->withTrashed()->search($filters['search'] ?? null)->ofType(($filters['type'] ?? '') ?: null);
        $range = function ($q, string $col) use ($filters) {
            $q->when($filters['from'] ?? null, fn ($w, $from) => $w->where($col, '>=', $from))
                ->when($filters['to'] ?? null, fn ($w, $to) => $w->where($col, '<=', $to))
                ->when($filters['member_id'] ?? null, fn ($w, $id) => $w->where('member_id', $id));
        };
        $member = fn (Member $m) => ['id' => $m->id, 'name' => $m->name, 'member_code' => $m->member_code];

        $events = collect();
        if ($kind === '' || $kind === 'bon') {
            $q = MemberCredit::with(['member' => fn ($q) => $q->withTrashed(), 'transaction:id,invoice_number'])->whereHas('member', $memberScope)->latest('created_at')->latest('id')->limit($limit);
            $range($q, 'created_at');
            $events = $events->concat($q->get()->map(fn (MemberCredit $c) => [
                'id' => 'bon-'.$c->id, 'date' => $c->created_at->toDateTimeString(), 'kind' => 'bon', 'number' => $c->number, 'amount' => $c->amount,
                'method' => null, 'note' => $c->transaction?->invoice_number ?? $c->note, 'status' => $c->status, 'member' => $member($c->member),
            ]));
        }
        if ($kind === '' || $kind === 'pelunasan') {
            $q = MemberCreditPayment::with(['member' => fn ($q) => $q->withTrashed(), 'allocations.credit:id,number'])->whereHas('member', $memberScope)->latest('paid_at')->latest('id')->limit($limit);
            $range($q, 'paid_at');
            $events = $events->concat($q->get()->map(fn (MemberCreditPayment $p) => [
                'id' => 'pln-'.$p->id, 'date' => $p->paid_at->toDateTimeString(), 'kind' => 'pelunasan', 'number' => $p->number, 'amount' => $p->amount,
                'method' => $p->method, 'note' => $p->note ?? $p->allocations->map(fn ($a) => $a->credit?->number)->filter()->join(', '), 'status' => null, 'member' => $member($p->member),
            ]));
        }
        $rows = $events->sortBy([['date', 'desc'], ['id', 'desc']])->take($limit)->values();

        return [
            'rows' => $rows->all(),
            'bon' => (int) $rows->where('kind', 'bon')->sum('amount'),
            'paid' => (int) $rows->where('kind', 'pelunasan')->sum('amount'),
        ];
    }

    /** Bon belum lunas anggota, jatuh tempo terlama dulu. */
    public function openBills(Member $member): array
    {
        return $member->credits()->open()->with('transaction:id,invoice_number')->orderBy('due_at')->orderBy('id')->get()
            ->map(fn (MemberCredit $c) => self::toArray($c))->all();
    }

    /** Pelunasan terbaru anggota. */
    public function payments(Member $member, int $limit = 10): array
    {
        return $member->creditPayments()->with(['payer:id,name', 'allocations.credit:id,number'])->latest('paid_at')->latest('id')->limit($limit)->get()
            ->map(fn (MemberCreditPayment $p) => self::paymentToArray($p))->all();
    }

    /** @return array<string, mixed> */
    public static function paymentToArray(MemberCreditPayment $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'amount' => $p->amount,
            'method' => $p->method,
            'method_label' => MemberCreditPayment::METHODS[$p->method] ?? $p->method,
            'note' => $p->note,
            'paid_by' => $p->payer?->name,
            'paid_at' => $p->paid_at->toDateTimeString(),
            'allocations' => $p->allocations->map(fn ($a) => ['credit_id' => $a->member_credit_id, 'number' => $a->credit?->number, 'amount' => $a->amount])->all(),
        ];
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

    private function createPaymentWithNumber(array $attributes): MemberCreditPayment
    {
        $prefix = 'PLN-'.now()->format('Ymd').'-';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = MemberCreditPayment::where('number', 'like', $prefix.'%')->max('number');
            $next = ($last ? (int) substr($last, strlen($prefix)) : 0) + 1 + $attempt;
            try {
                return DB::transaction(fn () => MemberCreditPayment::create([...$attributes, 'number' => $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT)]));
            } catch (UniqueConstraintViolationException) {
                // nomor dipakai pelunasan lain — ulangi
            }
        }

        throw new \RuntimeException('Gagal membuat nomor pelunasan unik.');
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
