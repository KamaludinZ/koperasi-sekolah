<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSavingAccount;
use App\Models\MemberSavingEntry;
use App\Models\SavingType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simpanan anggota per jenis (pokok/wajib/sukarela): ringkasan saldo & kewajiban setor.
 * Terpisah dari tabungan (SavingsService / members.balance).
 */
class MemberSavingService
{
    /**
     * Rincian per jenis untuk profil anggota: jenis aktif, plus jenis nonaktif yang masih bersaldo.
     *
     * @return array{total: int, types: list<array{id: int, code: string, name: string, kind: string, kind_label: string,
     *     amount: int, period: string, withdrawable: bool, balance: int, last_at: ?string, status: ?string,
     *     arrears_months: int, arrears_amount: int, paid_months: int}>}
     */
    public function summary(Member $member, ?Carbon $today = null): array
    {
        $today ??= now();
        $accounts = $member->savingAccounts()->get()->keyBy('saving_type_id');
        $last = MemberSavingEntry::where('member_id', $member->id)
            ->selectRaw('saving_type_id, MAX(created_at) AS last_at')->groupBy('saving_type_id')->pluck('last_at', 'saving_type_id');
        $paidPeriods = MemberSavingEntry::where('member_id', $member->id)->where('type', 'setor')->whereNotNull('period')
            ->select('saving_type_id', 'period')->distinct()->get()->groupBy('saving_type_id')->map->count();
        $deposited = MemberSavingEntry::where('member_id', $member->id)->where('type', 'setor')
            ->selectRaw('saving_type_id, SUM(amount) AS total')->groupBy('saving_type_id')->pluck('total', 'saving_type_id');

        $types = SavingType::ordered()->get()
            ->filter(fn (SavingType $t) => $t->is_active || (float) ($accounts[$t->id]->balance ?? 0) > 0)
            ->map(function (SavingType $t) use ($member, $today, $accounts, $last, $paidPeriods, $deposited) {
                $amount = (int) round((float) $t->amount);
                $balance = (int) round((float) ($accounts[$t->id]->balance ?? 0));
                [$status, $due, $paid] = [null, 0, (int) ($paidPeriods[$t->id] ?? 0)];

                if ($t->period === 'sekali' && $amount > 0) {
                    $status = (int) round((float) ($deposited[$t->id] ?? 0)) >= $amount ? 'lunas' : 'belum';
                } elseif ($t->period === 'bulanan' && $amount > 0) {
                    $due = self::monthsDue($member->joined_at ?? $member->created_at, $today);
                    $status = $paid >= $due ? 'lunas' : 'tunggak';
                }
                $arrears = max(0, $due - $paid);

                return [
                    'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'kind' => $t->kind,
                    'kind_label' => SavingType::KINDS[$t->kind] ?? $t->kind, 'amount' => $amount, 'period' => $t->period,
                    'withdrawable' => $t->withdrawable, 'is_active' => $t->is_active, 'balance' => $balance,
                    'last_at' => isset($last[$t->id]) ? Carbon::parse($last[$t->id])->toDateTimeString() : null,
                    'status' => $status, 'paid_months' => $paid, 'arrears_months' => $arrears, 'arrears_amount' => $arrears * $amount,
                ];
            })->values()->all();

        return ['total' => array_sum(array_column($types, 'balance')), 'types' => $types];
    }

    public const MIN_AMOUNT = 1_000;

    public const MAX_AMOUNT = 10_000_000;

    public const MAX_MONTHS = 12;

    /**
     * Setor ke satu jenis simpanan. Wajib bulanan: dibayar per bulan (mulai bulan tertua yang belum dibayar,
     * $months bulan sekaligus, nominal = nominal jenis); pokok: boleh dicicil s.d. nominal jenis; lainnya bebas.
     *
     * @return list<MemberSavingEntry>
     *
     * @throws ValidationException
     */
    public function deposit(Member $member, SavingType $type, int $amount, ?string $note = null, ?int $userId = null, int $months = 1, ?string $clientRef = null): array
    {
        if ($replay = $this->replay($member, $clientRef)) {
            return [$replay];
        }

        return DB::transaction(function () use ($member, $type, $amount, $note, $userId, $months, $clientRef) {
            [$locked, $account] = $this->lock($member, $type);
            if ($replay = $this->replay($member, $clientRef)) {
                return [$replay];
            }
            if (! $type->is_active) {
                throw ValidationException::withMessages(['type' => "{$type->name} sudah nonaktif; tidak menerima setoran baru."]);
            }
            $nominal = (int) round((float) $type->amount);

            if ($type->period === 'bulanan' && $nominal > 0) {
                if ($months < 1 || $months > self::MAX_MONTHS) {
                    throw ValidationException::withMessages(['months' => 'Pilih 1–'.self::MAX_MONTHS.' bulan.']);
                }
                $periods = $this->nextPeriods($locked, $type, $months);
                $entries = [];
                foreach ($periods as $i => $period) {
                    $entries[] = $this->post($locked, $account, $type, 'setor', $nominal, $note ?: 'Simpanan wajib '.Carbon::parse($period.'-01')->translatedFormat('F Y'),
                        $userId, $period, $i === 0 ? $clientRef : null);
                }
                $this->audit($locked, $type, 'setor', $nominal * count($periods), $entries, count($periods).' bulan ('.implode(', ', $periods).')');

                return $entries;
            }

            $this->assertAmount($amount);
            if ($type->period === 'sekali' && $nominal > 0) {
                $left = $nominal - (int) round((float) MemberSavingEntry::where('member_id', $locked->id)->where('saving_type_id', $type->id)->where('type', 'setor')->sum('amount'));
                if ($left <= 0) {
                    throw ValidationException::withMessages(['amount' => "{$type->name} sudah lunas."]);
                }
                if ($amount > $left) {
                    throw ValidationException::withMessages(['amount' => "Sisa {$type->name} hanya ".\App\Support\Rupiah::format($left).'.']);
                }
            }
            $entry = $this->post($locked, $account, $type, 'setor', $amount, $note ?: "Setoran {$type->name}", $userId, null, $clientRef);
            $this->audit($locked, $type, 'setor', $amount, [$entry]);

            return [$entry];
        });
    }

    /** @throws ValidationException */
    public function withdraw(Member $member, SavingType $type, int $amount, ?string $note = null, ?int $userId = null, ?string $clientRef = null): MemberSavingEntry
    {
        if ($replay = $this->replay($member, $clientRef)) {
            return $replay;
        }

        return DB::transaction(function () use ($member, $type, $amount, $note, $userId, $clientRef) {
            [$locked, $account] = $this->lock($member, $type);
            if ($replay = $this->replay($member, $clientRef)) {
                return $replay;
            }
            if (! $type->withdrawable) {
                throw ValidationException::withMessages(['type' => "{$type->name} tidak bisa ditarik selama masih menjadi anggota."]);
            }
            $this->assertAmount($amount);
            $balance = (int) round((float) $account->balance);
            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'Penarikan melebihi saldo '.$type->name.' ('.\App\Support\Rupiah::format($balance).').']);
            }
            $entry = $this->post($locked, $account, $type, 'tarik', $amount, $note ?: "Penarikan {$type->name}", $userId, null, $clientRef);
            $this->audit($locked, $type, 'tarik', $amount, [$entry]);

            return $entry;
        });
    }

    /** Bulan wajib (YYYY-MM) yang belum dibayar, terlama dulu. */
    public function unpaidPeriods(Member $member, SavingType $type, int $limit = 24): array
    {
        $due = self::monthsDue($member->joined_at ?? $member->created_at, now());
        $start = Carbon::parse($member->joined_at ?? $member->created_at)->startOfMonth();
        $paid = MemberSavingEntry::where('member_id', $member->id)->where('saving_type_id', $type->id)->where('type', 'setor')
            ->whereNotNull('period')->pluck('period')->all();
        $out = [];
        for ($i = 0; $i < $due && count($out) < $limit; $i++) {
            $p = $start->copy()->addMonths($i)->format('Y-m');
            if (! in_array($p, $paid, true)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Bulan (YYYY-MM) yang dibayar oleh setoran wajib $months bulan: tunggakan terlama dulu, lalu
     * bulan-bulan berikutnya (setor di muka) bila tunggakan sudah habis.
     */
    public function nextPeriods(Member $member, SavingType $type, int $months): array
    {
        $periods = array_slice($this->unpaidPeriods($member, $type, $months), 0, $months);
        $next = Carbon::parse(($this->lastPaidPeriod($member, $type) ?? now()->subMonth()->format('Y-m')).'-01');
        while (count($periods) < $months) {
            $next = $next->addMonth();
            $p = $next->format('Y-m');
            if (! in_array($p, $periods, true) && ! $this->isPaid($member, $type, $p)) {
                $periods[] = $p;
            }
        }
        sort($periods);

        return $periods;
    }

    private function lastPaidPeriod(Member $member, SavingType $type): ?string
    {
        return MemberSavingEntry::where('member_id', $member->id)->where('saving_type_id', $type->id)->where('type', 'setor')->max('period');
    }

    private function isPaid(Member $member, SavingType $type, string $period): bool
    {
        return MemberSavingEntry::where('member_id', $member->id)->where('saving_type_id', $type->id)->where('type', 'setor')->where('period', $period)->exists();
    }

    /** @return array{0: Member, 1: MemberSavingAccount} anggota & saldo jenis yang sudah dikunci */
    private function lock(Member $member, SavingType $type): array
    {
        $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
        if (! $locked->is_active) {
            throw ValidationException::withMessages(['member' => 'Anggota nonaktif tidak bisa setor/tarik simpanan.']);
        }
        MemberSavingAccount::firstOrCreate(['member_id' => $locked->id, 'saving_type_id' => $type->id], ['balance' => 0]);
        $account = MemberSavingAccount::where('member_id', $locked->id)->where('saving_type_id', $type->id)->lockForUpdate()->firstOrFail();

        return [$locked, $account];
    }

    private function assertAmount(int $amount): void
    {
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => $amount < self::MIN_AMOUNT
                ? 'Minimal '.\App\Support\Rupiah::format(self::MIN_AMOUNT).'.'
                : 'Maksimal '.\App\Support\Rupiah::format(self::MAX_AMOUNT).' per transaksi.']);
        }
    }

    private function post(Member $member, MemberSavingAccount $account, SavingType $type, string $kind, int $amount, ?string $note, ?int $userId, ?string $period, ?string $clientRef): MemberSavingEntry
    {
        $before = (int) round((float) $account->balance);
        $after = $kind === 'setor' ? $before + $amount : $before - $amount;
        $entry = MemberSavingEntry::create([
            'member_id' => $member->id, 'saving_type_id' => $type->id, 'type' => $kind, 'amount' => $amount,
            'balance_before' => $before, 'balance_after' => $after, 'period' => $period,
            'note' => $note !== null ? mb_substr(trim($note), 0, 200) : null, 'user_id' => $userId, 'client_ref' => $clientRef,
        ]);
        $account->forceFill(['balance' => $after])->save();

        return $entry;
    }

    /** @param  list<MemberSavingEntry>  $entries */
    private function audit(Member $member, SavingType $type, string $kind, int $amount, array $entries, ?string $detail = null): void
    {
        $last = end($entries);
        AuditLog::record("simpanan.{$kind}", 'simpanan',
            ($kind === 'setor' ? 'Setoran ' : 'Penarikan ')."{$type->name} {$member->name}: ".\App\Support\Rupiah::format($amount)
                .($detail ? " · {$detail}" : '').' (saldo '.\App\Support\Rupiah::format((int) $last->balance_after).')',
            ['type' => 'member', 'id' => $member->id, 'label' => "{$member->member_code} · {$member->name}"],
            ['saving_type_id' => $type->id, 'entry_ids' => array_map(fn ($e) => $e->id, $entries),
                'balance_before' => (int) $entries[0]->balance_before, 'balance_after' => (int) $last->balance_after,
                'periods' => array_values(array_filter(array_map(fn ($e) => $e->period, $entries)))],
            $amount);
    }

    private function replay(Member $member, ?string $clientRef): ?MemberSavingEntry
    {
        if ($clientRef === null) {
            return null;
        }
        $entry = MemberSavingEntry::where('client_ref', $clientRef)->first();
        if ($entry && $entry->member_id !== $member->id) {
            throw ValidationException::withMessages(['client_ref' => 'Kunci idempoten sudah dipakai untuk anggota lain.']);
        }

        return $entry;
    }

    /** Jumlah bulan wajib setor dari bulan bergabung s.d. bulan ini (termasuk keduanya). */
    public static function monthsDue(?\DateTimeInterface $joinedAt, Carbon $today): int
    {
        if (! $joinedAt) {
            return 0;
        }
        $start = Carbon::parse($joinedAt)->startOfMonth();

        return $start->greaterThan($today) ? 0 : (int) $start->diffInMonths($today->copy()->startOfMonth()) + 1;
    }
}
