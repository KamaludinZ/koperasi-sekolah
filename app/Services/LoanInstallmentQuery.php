<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Support\LoanOverdue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar angsuran lintas pinjaman (halaman Angsuran): filter tahap (jatuh tempo bulan ini, terlambat, belum, dibayar),
 * bulan jatuh tempo (Y-m), metode bayar pinjaman, dan pencarian kode pinjaman/nama/kode anggota.
 */
class LoanInstallmentQuery
{
    public const FILTERS = ['bulan_ini' => 'Jatuh tempo bulan ini', 'terlambat' => 'Lewat jatuh tempo', 'belum' => 'Belum dibayar', 'dibayar' => 'Sudah dibayar', 'semua' => 'Semua'];

    /** @param  array{filter?: string, month?: string, method?: string, search?: string}  $f */
    public function query(array $f = []): Builder
    {
        $filter = array_key_exists($f['filter'] ?? '', self::FILTERS) ? $f['filter'] : 'bulan_ini';
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim(mb_substr($f['search'] ?? '', 0, 100))));
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $f['month'] ?? '') ? $f['month'] : null;

        return LoanInstallment::query()
            ->with(['loan:id,code,member_id,status,payment_method,tenor_months', 'loan.member:id,member_code,name,class,type,deleted_at'])
            ->whereHas('loan', fn ($q) => $q->whereIn('status', [...Loan::ACTIVE, 'lunas'])
                ->when(array_key_exists($f['method'] ?? '', Loan::METHODS), fn ($q) => $q->where('payment_method', $f['method']))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw("LOWER(code) LIKE ? ESCAPE '\\'", ["%{$term}%"])
                    ->orWhereHas('member', fn ($m) => $m->withTrashed()->where(fn ($x) => $x->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", ["%{$term}%"])
                        ->orWhereRaw("LOWER(member_code) LIKE ? ESCAPE '\\'", ["%{$term}%"]))))))
            ->when($month, fn ($q) => $q->whereDayRange('due_date', $month.'-01', \Illuminate\Support\Carbon::parse($month.'-01')->endOfMonth()->toDateString()))
            ->when($filter === 'bulan_ini', fn ($q) => $q->whereDayRange('due_date', today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()))
            ->when($filter === 'terlambat', fn ($q) => LoanOverdue::scope($q))
            ->when($filter === 'belum', fn ($q) => $q->where('status', '!=', 'dibayar'))
            ->when($filter === 'dibayar', fn ($q) => $q->where('status', 'dibayar'))
            ->orderByRaw("CASE WHEN status <> 'dibayar' AND due_date < ? THEN 0 WHEN status <> 'dibayar' THEN 1 ELSE 2 END", [LoanOverdue::cutoff()])
            ->orderBy($filter === 'dibayar' ? 'paid_at' : 'due_date', $filter === 'dibayar' ? 'desc' : 'asc')->orderBy('loan_id')->orderBy('sequence');
    }

    public static function toArray(LoanInstallment $i): array
    {

        return [
            'id' => $i->id, 'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(),
            'amount' => (int) round((float) $i->amount), 'paid_amount' => (int) round((float) $i->paid_amount),
            'remaining' => max(0, (int) round((float) $i->amount - (float) $i->paid_amount)),
            'paid_at' => $i->paid_at?->toDateString(), 'payment_method' => $i->payment_method,
            'status' => $i->status, 'late' => LoanOverdue::isLate($i), 'days_late' => LoanOverdue::daysLate($i),
            'loan' => ['id' => $i->loan->id, 'code' => $i->loan->code, 'tenor_months' => $i->loan->tenor_months,
                'payment_method' => $i->loan->payment_method, 'payment_label' => Loan::METHODS[$i->loan->payment_method] ?? $i->loan->payment_method],
            'member' => $i->loan->member ? ['id' => $i->loan->member->id, 'code' => $i->loan->member->member_code, 'name' => $i->loan->member->name] : null,
        ];
    }

    /** Ringkasan untuk kartu: jatuh tempo bulan ini, terlambat, terbayar bulan ini. */
    public function summary(): array
    {
        $base = fn () => LoanInstallment::query()->whereHas('loan', fn ($q) => $q->whereIn('status', [...Loan::ACTIVE, 'lunas']));
        $from = today()->startOfMonth()->toDateString();
        $to = today()->endOfMonth()->toDateString();
        $remain = \Illuminate\Support\Facades\DB::raw('amount - paid_amount');

        return [
            'due_month' => ['count' => $base()->whereDayRange('due_date', $from, $to)->where('status', '!=', 'dibayar')->count(),
                'amount' => (int) round((float) $base()->whereDayRange('due_date', $from, $to)->where('status', '!=', 'dibayar')->sum($remain))],
            'late' => ['count' => LoanOverdue::scope($base())->count(),
                'amount' => (int) round((float) LoanOverdue::scope($base())->sum($remain))],
            'paid_month' => ['count' => $base()->whereDayRange('paid_at', $from, $to)->count(),
                'amount' => (int) round((float) $base()->whereDayRange('paid_at', $from, $to)->sum('paid_amount'))],
        ];
    }
}
