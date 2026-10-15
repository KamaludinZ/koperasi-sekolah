<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Notification;
use App\Support\LoanOverdue;
use Illuminate\Support\Facades\DB;

/**
 * Deteksi angsuran lewat jatuh tempo (dijalankan job DetectOverdueLoans tiap hari & lewat perintah
 * pinjaman:deteksi-tunggakan). Hanya pinjaman yang statusnya tidak lagi sesuai aturan LoanOverdue yang disentuh:
 * angsuran "belum" yang sudah lewat jatuh tempo, angsuran "menunggak" yang ternyata belum lewat (mis. jadwal
 * digeser/toleransi diubah), dan pinjaman yang status menunggak-nya tidak cocok dengan angsurannya. Status angsuran &
 * pinjaman disegarkan (riwayat perubahan angsuran tercatat otomatis), pinjaman yang BARU menunggak dicatat di jejak
 * audit dan dikabarkan ke pengurus lewat notifikasi (sekali per tanggal mulai menunggak).
 */
class LoanOverdueDetector
{
    public function __construct(private LoanPaymentService $payments) {}

    /** @return list<int> id pinjaman yang perlu disegarkan */
    public function candidates(): array
    {
        $cutoff = LoanOverdue::cutoff();
        $fromInstallments = LoanInstallment::query()->where('status', '!=', 'dibayar')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('status', 'belum')->where('due_date', '<', $cutoff))
                ->orWhere(fn ($w) => $w->where('status', 'menunggak')->where('due_date', '>=', $cutoff)))
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::ACTIVE))->distinct()->pluck('loan_id');
        $lagging = Loan::query()->where('status', 'berjalan')->effectivelyOverdue()->pluck('id');
        $stale = Loan::query()->where('status', 'menunggak')->whereDoesntHave('installments', fn ($i) => LoanOverdue::scope($i))->pluck('id');

        return $fromInstallments->merge($lagging)->merge($stale)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** @return array{checked: int, overdue: list<string>, recovered: list<string>, installments: int} */
    public function run(): array
    {
        $result = ['checked' => 0, 'overdue' => [], 'recovered' => [], 'installments' => 0];
        foreach ($this->candidates() as $id) {
            DB::transaction(function () use ($id, &$result) {
                $loan = Loan::whereKey($id)->lockForUpdate()->first();
                if (! $loan || ! in_array($loan->status, Loan::ACTIVE, true)) {
                    return;
                }
                $before = $loan->status;
                $changesBefore = $loan->installmentChanges()->count();
                $this->payments->refreshLoan($loan);
                $loan->refresh();
                $result['checked']++;
                $result['installments'] += $loan->installmentChanges()->count() - $changesBefore;

                if ($before !== 'menunggak' && $loan->status === 'menunggak') {
                    $result['overdue'][] = $loan->code;
                    $this->announce($loan);
                } elseif ($before === 'menunggak' && $loan->status !== 'menunggak') {
                    $result['recovered'][] = $loan->code;
                }
            });
        }

        return $result;
    }

    private function announce(Loan $loan): void
    {
        $loan->load('member:id,member_code,name,deleted_at');
        $late = LoanOverdue::scope($loan->installments()->getQuery()->reorder())->get(['amount', 'paid_amount']);
        $amount = (int) $late->sum(fn ($i) => (int) round((float) $i->amount - (float) $i->paid_amount));
        $since = $loan->overdue_since?->toDateString();

        AuditLog::record('pinjaman.menunggak', 'pinjaman',
            "Pinjaman {$loan->code} {$loan->member?->name} menunggak sejak ".\App\Support\Tanggal::format($since).': '.$late->count().' angsuran, '.\App\Support\Rupiah::format($amount),
            LoanApplicationService::subject($loan), ['overdue_since' => $since, 'installments' => $late->count()], $amount,
            action: 'diubah', old: ['status' => 'berjalan'], new: ['status' => 'menunggak', 'overdue_since' => $since]);

        Notification::firstOrCreate(['dedupe_key' => "pinjaman:menunggak:{$loan->id}:{$since}"], [
            'type' => 'sistem', 'target' => 'pengurus',
            'title' => 'Pinjaman menunggak: '.($loan->member?->name ?? $loan->code),
            'message' => "{$loan->code}: {$late->count()} angsuran lewat jatuh tempo, tertunggak ".\App\Support\Rupiah::format($amount).' sejak '.\App\Support\Tanggal::format($since).'.',
            'url' => route('pinjaman.tunggakan', ['q' => $loan->code], false),
        ]);
    }
}
