<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\SavingType;
use Illuminate\Support\Carbon;

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
