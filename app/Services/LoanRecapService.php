<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanInstallment;
use App\Models\LoanPayment;
use Illuminate\Support\Carbon;

/**
 * Rekap pinjaman per periode (bulan atau tahun) untuk halaman Rekap: pengajuan & keputusan, dana dicairkan,
 * angsuran diterima (pembayaran sah) dipecah per metode dan porsi pokok/bunga, serta posisi portofolio saat ini
 * (sisa berjalan, tunggakan), agregasi pinjaman berjalan (running) dan rincian per bulan untuk periode > 1 bulan.
 */
class LoanRecapService
{
    /** Rentang bebas terpanjang (hari). */
    public const MAX_RANGE_DAYS = 366;

    /**
     * Rentang tanggal bebas (Y-m-d), divalidasi: format benar, awal ≤ akhir, akhir ≤ hari ini, paling panjang
     * MAX_RANGE_DAYS hari. Null bila tidak sah.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}|null
     */
    public static function range(string $from, string $to): ?array
    {
        $valid = fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        if (! $valid($from) || ! $valid($to) || $from > $to || $to > today()->toDateString()) {
            return null;
        }
        [$f, $t] = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->startOfDay()];
        if ($f->diffInDays($t) + 1 > self::MAX_RANGE_DAYS) {
            return null;
        }

        return [$f, $t, \App\Support\Tanggal::format($from).' – '.\App\Support\Tanggal::format($to)];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} awal, akhir, label periode */
    public static function period(string $type, string $value): array
    {
        if ($type === 'tahun' && preg_match('/^\d{4}$/', $value)) {
            $from = Carbon::create((int) $value, 1, 1)->startOfDay();

            return [$from, $from->copy()->endOfYear()->startOfDay(), 'Tahun '.$value];
        }
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : today()->format('Y-m');
        $from = Carbon::parse($month.'-01')->startOfDay();

        return [$from, $from->copy()->endOfMonth()->startOfDay(), $from->locale('id')->translatedFormat('F Y')];
    }

    public function recap(Carbon $from, Carbon $to): array
    {
        [$f, $t] = [$from->toDateString(), $to->toDateString()];
        $payments = LoanPayment::query()->whereNull('voided_at')->whereDayRange('paid_at', $f, $t)->get(['id', 'amount', 'payment_method', 'allocations', 'paid_at']);
        $split = $this->split($payments);

        $disb = LoanDisbursement::query()->whereDayRange('disbursed_on', $f, $t)->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')->first();
        $applied = Loan::query()->whereDayRange('applied_at', $f, $t);
        $decided = fn (string $status) => Loan::query()->where('status', '!=', 'diajukan')->whereNotNull('decided_at')
            ->whereDayRange('decided_at', $f, $t)->when($status === 'ditolak', fn ($q) => $q->where('status', 'ditolak'), fn ($q) => $q->where('status', '!=', 'ditolak'))->count();

        return [
            'period' => ['from' => $f, 'to' => $t],
            'applications' => ['count' => (clone $applied)->count(), 'amount' => (int) round((float) (clone $applied)->sum('principal')),
                'approved' => $decided('disetujui'), 'rejected' => $decided('ditolak')],
            'disbursed' => ['count' => (int) $disb->n, 'amount' => (int) round((float) $disb->total)],
            'collected' => [
                'count' => $payments->count(), 'amount' => (int) round((float) $payments->sum('amount')),
                'principal' => $split['principal'], 'interest' => $split['interest'],
                'by_method' => $this->byMethod($payments),
            ],
            'portfolio' => $this->portfolio(),
            'running' => $this->running(),
            'months' => $from->format('Y-m') !== $to->format('Y-m') ? $this->months($from, $to) : [],
        ];
    }

    /**
     * Posisi saat ini (tidak bergantung periode): pinjaman aktif & sisanya (pokok/bunga belum dibayar), yang menunggak
     * (keadaan efektif), yang lunas, serta kumulatif dana tercairkan dan angsuran sah yang sudah kembali.
     */
    public function portfolio(): array
    {
        $active = Loan::query()->whereIn('status', Loan::ACTIVE);
        $overdue = Loan::query()->effectivelyOverdue();
        $open = LoanInstallment::query()->where('status', '!=', 'dibayar')->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::ACTIVE))
            ->get(['amount', 'paid_amount', 'interest_part']);
        $interestLeft = (int) $open->sum(function ($i) {
            $amount = (int) round((float) $i->amount);
            $part = (int) round((float) $i->interest_part);

            return $amount > 0 ? $part - intdiv($part * (int) round((float) $i->paid_amount), $amount) : 0;
        });
        $outstanding = (int) round((float) (clone $active)->sum('outstanding'));
        $disbursed = (int) round((float) LoanDisbursement::query()->sum('amount'));
        $collected = (int) round((float) LoanPayment::query()->whereNull('voided_at')->sum('amount'));
        $due = (int) round((float) Loan::query()->whereIn('status', [...Loan::ACTIVE, 'lunas'])->sum(\Illuminate\Support\Facades\DB::raw('principal + interest')));

        return [
            'active' => (clone $active)->count(), 'outstanding' => $outstanding,
            'principal_outstanding' => $outstanding - $interestLeft, 'interest_outstanding' => $interestLeft,
            'overdue' => (clone $overdue)->count(), 'overdue_outstanding' => (int) round((float) (clone $overdue)->sum('outstanding')),
            'paid_off' => Loan::query()->where('status', 'lunas')->count(),
            'disbursed_total' => $disbursed, 'disbursed_count' => LoanDisbursement::query()->count(),
            'collected_total' => $collected,
            // persentase kewajiban (pokok + bunga pinjaman yang sudah cair) yang sudah kembali
            'collection_rate' => $due > 0 ? round($collected * 100 / $due, 1) : 0.0,
        ];
    }

    /**
     * Rincian per bulan dalam tiga query (GROUP BY bulan) — bukan tiga query per bulan; bulan tanpa kegiatan tetap
     * muncul bernilai 0. Bulan pertama/terakhir otomatis terpotong batas periode karena saringan tanggal.
     *
     * @return list<array{month: string, label: string, disbursed: int, collected: int, count: int}>
     */
    private function months(Carbon $from, Carbon $to): array
    {
        [$f, $t] = [$from->toDateString(), $to->toDateString()];
        $ym = fn (string $col) => "substr(CAST({$col} AS TEXT), 1, 7)";
        $disb = LoanDisbursement::query()->whereDayRange('disbursed_on', $f, $t)
            ->selectRaw($ym('disbursed_on').' AS ym, COALESCE(SUM(amount), 0) AS total')->groupByRaw($ym('disbursed_on'))->pluck('total', 'ym');
        $pay = LoanPayment::query()->whereNull('voided_at')->whereDayRange('paid_at', $f, $t)
            ->selectRaw($ym('paid_at').' AS ym, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n')->groupByRaw($ym('paid_at'))->get()->keyBy('ym');

        $out = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            $k = $m->format('Y-m');
            $out[] = ['month' => $k, 'label' => $m->locale('id')->translatedFormat('M Y'),
                'disbursed' => (int) round((float) ($disb[$k] ?? 0)),
                'collected' => (int) round((float) ($pay[$k]->total ?? 0)), 'count' => (int) ($pay[$k]->n ?? 0)];
        }

        return $out;
    }

    /** Kelompok sisa angsuran pinjaman berjalan. */
    public const REMAINING_BUCKETS = ['1' => '1 angsuran', '2-3' => '2–3 angsuran', '4-6' => '4–6 angsuran', '7-12' => '7–12 angsuran', '13+' => 'Lebih dari 12'];

    /**
     * Agregasi pinjaman berjalan (berjalan + menunggak) saat ini: jumlah, pokok, kewajiban total, sudah dibayar, sisa,
     * rata-rata sisa; sebaran per jenis anggota, per metode bayar, per sisa angsuran; dan perkiraan angsuran masuk
     * 30 hari ke depan (sisa angsuran yang jatuh tempo, termasuk yang sudah lewat).
     */
    public function running(?Carbon $today = null): array
    {
        $today ??= today();
        $loans = Loan::query()->whereIn('status', Loan::ACTIVE)->with('member:id,type,deleted_at')
            ->withCount(['installments as open_installments' => fn ($q) => $q->where('status', '!=', 'dibayar')])
            ->get(['id', 'member_id', 'principal', 'interest', 'outstanding', 'payment_method', 'status']);
        $overdue = Loan::query()->effectivelyOverdue()->pluck('id')->flip();
        $sum = fn ($c, string $f) => (int) round((float) $c->sum($f));
        $due = $loans->sum(fn ($l) => (int) round((float) $l->principal + (float) $l->interest));
        $outstanding = $sum($loans, 'outstanding');
        $bucket = fn (int $n) => match (true) { $n <= 1 => '1', $n <= 3 => '2-3', $n <= 6 => '4-6', $n <= 12 => '7-12', default => '13+' };
        $group = fn ($c) => ['count' => $c->count(), 'outstanding' => $sum($c, 'outstanding')];
        $soon = \App\Models\LoanInstallment::query()->where('status', '!=', 'dibayar')->where('due_date', '<', $today->copy()->addDays(31)->toDateString())
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::ACTIVE))->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount - paid_amount), 0) AS sisa')->first();

        return [
            'count' => $loans->count(), 'principal' => $sum($loans, 'principal'), 'total_due' => $due,
            'paid' => $due - $outstanding, 'outstanding' => $outstanding,
            'average_outstanding' => $loans->count() ? intdiv($outstanding, $loans->count()) : 0,
            'overdue' => $group($loans->filter(fn ($l) => $overdue->has($l->id))),
            'by_member_type' => collect(\App\Models\Member::TYPES)->map(fn ($label, $t) => ['label' => $label] + $group($loans->filter(fn ($l) => $l->member?->type === $t)))->all(),
            'by_method' => collect(Loan::METHODS)->map(fn ($label, $m) => ['label' => $label] + $group($loans->where('payment_method', $m)))->all(),
            'by_remaining' => collect(self::REMAINING_BUCKETS)->map(fn ($label, $k) => ['label' => $label] + $group($loans->filter(fn ($l) => $bucket((int) $l->open_installments) === $k)))->all(),
            'expected_30_days' => ['installments' => (int) $soon->n, 'amount' => (int) round((float) $soon->sisa)],
        ];
    }

    /**
     * Rekap angsuran per metode bayar: jumlah pembayaran, nominal, porsi pokok/bunga, bagian dari total periode, dan
     * banyaknya pinjaman yang dibayar; ditambah pinjaman aktif yang memilih metode itu beserta sisanya (posisi saat ini).
     *
     * @return list<array>
     */
    private function byMethod($payments): array
    {
        $total = (int) round((float) $payments->sum('amount'));
        $active = Loan::query()->whereIn('status', Loan::ACTIVE)->selectRaw('payment_method, COUNT(*) AS n, COALESCE(SUM(outstanding), 0) AS sisa')
            ->groupBy('payment_method')->get()->keyBy('payment_method');
        $loanIds = LoanPayment::query()->whereIn('id', $payments->pluck('id'))->get(['id', 'loan_id'])->pluck('loan_id', 'id');

        return collect(Loan::METHODS)->map(function ($label, $m) use ($payments, $total, $active, $loanIds) {
            $rows = $payments->where('payment_method', $m);
            $split = $this->split($rows);
            $amount = (int) round((float) $rows->sum('amount'));

            return ['method' => $m, 'label' => $label, 'count' => $rows->count(), 'amount' => $amount,
                'principal' => $split['principal'], 'interest' => $split['interest'],
                'share' => $total > 0 ? round($amount * 100 / $total, 1) : 0.0,
                'loans_paid' => $rows->map(fn ($p) => $loanIds[$p->id] ?? null)->filter()->unique()->count(),
                'active_loans' => (int) ($active[$m]->n ?? 0), 'active_outstanding' => (int) round((float) ($active[$m]->sisa ?? 0))];
        })->values()->all();
    }

    /** Porsi pokok & bunga dari pembayaran: tiap alokasi dibagi menurut porsi bunga angsuran yang dibayar. */
    private function split($payments): array
    {
        $ids = $payments->flatMap(fn ($p) => array_column($p->allocations ?? [], 'installment_id'))->unique()->values();
        $inst = LoanInstallment::query()->whereIn('id', $ids)->get(['id', 'amount', 'interest_part'])->keyBy('id');
        $interest = 0;
        foreach ($payments as $p) {
            foreach ($p->allocations ?? [] as $a) {
                $i = $inst[$a['installment_id']] ?? null;
                $amount = $i ? (int) round((float) $i->amount) : 0;
                $interest += $amount > 0 ? intdiv((int) round((float) $i->interest_part) * (int) $a['amount'], $amount) : 0;
            }
        }
        $collected = (int) round((float) $payments->sum('amount'));

        return ['interest' => $interest, 'principal' => $collected - $interest];
    }
}
