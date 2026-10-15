<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Support\LoanOverdue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daftar pinjaman menunggak (halaman Tunggakan): satu baris per pinjaman berjalan/menunggak yang punya angsuran lewat
 * jatuh tempo (aturan LoanOverdue) — jumlah angsuran & nominal tertunggak, jatuh tempo tertua, lama terlambat, dan
 * kelompok umur tunggakan (1–30, 31–60, 61–90, >90 hari). Saring umur, metode bayar, pencarian kode/nama/kode anggota;
 * urut terlama (bawaan) atau nominal terbesar.
 */
class LoanArrearsQuery
{
    public const BUCKETS = ['1-30' => '1–30 hari', '31-60' => '31–60 hari', '61-90' => '61–90 hari', '90+' => 'Lebih dari 90 hari'];

    public const SORTS = ['terlama' => 'Terlama', 'terbesar' => 'Nominal terbesar'];

    public static function bucket(int $days): string
    {
        return match (true) {
            $days <= 30 => '1-30', $days <= 60 => '31-60', $days <= 90 => '61-90', default => '90+',
        };
    }

    /**
     * Semua pinjaman menunggak (belum disaring), urut terlama.
     *
     * @return Collection<int, array>
     */
    public function all(?Carbon $today = null): Collection
    {
        $today ??= today();
        $agg = LoanOverdue::scope(LoanInstallment::query(), $today)
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::ACTIVE))
            ->groupBy('loan_id')
            ->select('loan_id', DB::raw('COUNT(*) AS n'), DB::raw('SUM(amount - paid_amount) AS due'), DB::raw('MIN(due_date) AS oldest'))
            ->get()->keyBy('loan_id');
        if ($agg->isEmpty()) {
            return collect();
        }
        $loans = Loan::with('member:id,member_code,name,class,type,phone,deleted_at')->whereIn('id', $agg->keys())->get();

        return $loans->map(function (Loan $l) use ($agg, $today) {
            $a = $agg[$l->id];
            $oldest = Carbon::parse($a->oldest)->startOfDay();
            $days = (int) $oldest->diffInDays($today->copy()->startOfDay());

            return [
                'id' => $l->id, 'code' => $l->code, 'status' => $l->status, 'payment_method' => $l->payment_method,
                'payment_label' => Loan::METHODS[$l->payment_method] ?? $l->payment_method,
                'member' => $l->member ? ['id' => $l->member->id, 'code' => $l->member->member_code, 'name' => $l->member->name,
                    'class' => $l->member->class, 'phone' => $l->member->phone] : null,
                'late_count' => (int) $a->n, 'late_amount' => (int) round((float) $a->due), 'oldest_due' => $oldest->toDateString(),
                'days_late' => $days, 'bucket' => self::bucket($days),
                'outstanding' => (int) round((float) $l->outstanding), 'tenor_months' => $l->tenor_months,
            ];
        })->sortBy([['days_late', 'desc'], ['late_amount', 'desc'], ['id', 'asc']])->values();
    }

    /** @param  array{bucket?: string, method?: string, search?: string, sort?: string}  $f */
    public static function filter(Collection $rows, array $f): Collection
    {
        $term = mb_strtolower(trim(mb_substr($f['search'] ?? '', 0, 100)));

        return $rows
            ->when(array_key_exists($f['bucket'] ?? '', self::BUCKETS), fn ($c) => $c->where('bucket', $f['bucket']))
            ->when(array_key_exists($f['method'] ?? '', Loan::METHODS), fn ($c) => $c->where('payment_method', $f['method']))
            ->when($term !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['code']), $term)
                || str_contains(mb_strtolower($r['member']['name'] ?? ''), $term) || str_contains(mb_strtolower($r['member']['code'] ?? ''), $term)))
            ->when(($f['sort'] ?? 'terlama') === 'terbesar', fn ($c) => $c->sortBy([['late_amount', 'desc'], ['days_late', 'desc'], ['id', 'asc']]))
            ->values();
    }

    /** Ringkasan kartu: jumlah pinjaman & nominal tertunggak total dan per kelompok umur. */
    public static function summary(Collection $rows): array
    {
        return [
            'loans' => $rows->count(), 'amount' => (int) $rows->sum('late_amount'), 'installments' => (int) $rows->sum('late_count'),
            'buckets' => collect(self::BUCKETS)->map(fn ($label, $key) => ['label' => $label,
                'count' => $rows->where('bucket', $key)->count(), 'amount' => (int) $rows->where('bucket', $key)->sum('late_amount')])->all(),
        ];
    }
}
