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
 * (sisa berjalan, tunggakan) dan rincian per bulan untuk periode tahunan.
 */
class LoanRecapService
{
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
                'by_method' => collect(Loan::METHODS)->map(fn ($label, $m) => ['method' => $m, 'label' => $label,
                    'count' => $payments->where('payment_method', $m)->count(),
                    'amount' => (int) round((float) $payments->where('payment_method', $m)->sum('amount'))])->values()->all(),
            ],
            'portfolio' => $this->portfolio(),
            'months' => $from->diffInMonths($to) >= 1 ? $this->months($from, $to) : [],
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

    /** @return list<array{month: string, label: string, disbursed: int, collected: int, count: int}> */
    private function months(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            [$f, $t] = [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()];
            $pay = LoanPayment::query()->whereNull('voided_at')->whereDayRange('paid_at', $f, $t);
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->locale('id')->translatedFormat('M Y'),
                'disbursed' => (int) round((float) LoanDisbursement::query()->whereDayRange('disbursed_on', $f, $t)->sum('amount')),
                'collected' => (int) round((float) (clone $pay)->sum('amount')), 'count' => (clone $pay)->count()];
        }

        return $out;
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
