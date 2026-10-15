<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanInstallment;
use Illuminate\Database\Eloquent\Builder;

/** Pinjaman anggota: daftar/konsol untuk pengurus & bentuk data tampilan. */
class LoanService
{
    /**
     * Pinjaman untuk konsol: yang perlu tindakan (diajukan/menunggak) lalu berjalan dulu, terbaru dulu.
     *
     * @param  array{search?: string, status?: string, method?: string, member_id?: int|null}  $f
     */
    public function query(array $f = []): Builder
    {
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($f['search'] ?? ''))); // wildcard LIKE diperlakukan harfiah

        return Loan::query()->with('member:id,member_code,name,class,type,deleted_at')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw("LOWER(code) LIKE ? ESCAPE '\\'", ["%{$term}%"])
                ->orWhereHas('member', fn ($m) => $m->withTrashed()->where(fn ($x) => $x->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", ["%{$term}%"])->orWhereRaw("LOWER(member_code) LIKE ? ESCAPE '\\'", ["%{$term}%"])))))
            // menunggak/berjalan memakai keadaan efektif (angsuran lewat jatuh tempo), bukan hanya kolom status
            ->when(($f['status'] ?? '') === 'menunggak', fn ($q) => $q->effectivelyOverdue())
            ->when(($f['status'] ?? '') === 'berjalan', fn ($q) => $q->where('status', 'berjalan')->whereNotIn('id', Loan::query()->effectivelyOverdue()->select('id')))
            ->when(! in_array($f['status'] ?? '', ['', 'menunggak', 'berjalan'], true), fn ($q) => $q->where('status', $f['status']))
            ->when(($f['method'] ?? '') !== '', fn ($q) => $q->where('payment_method', $f['method']))
            ->when(! empty($f['member_id']), fn ($q) => $q->where('member_id', (int) $f['member_id']))
            ->orderByRaw("CASE status WHEN 'menunggak' THEN 0 WHEN 'diajukan' THEN 1 WHEN 'disetujui' THEN 2 WHEN 'berjalan' THEN 3 ELSE 4 END")
            ->orderByDesc('applied_at')->orderByDesc('id');
    }

    /**
     * Ringkasan konsol: jumlah pinjaman per status & total sisa pinjaman aktif (berjalan + menunggak).
     *
     * @return array{counts: array<string, int>, outstanding: int, overdue_outstanding: int}
     */
    public function stats(): array
    {
        $rows = Loan::query()->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(outstanding), 0) AS sisa')->groupBy('status')->get()->keyBy('status');
        $counts = collect(array_keys(Loan::STATUSES))->mapWithKeys(fn ($s) => [$s => (int) ($rows[$s]->n ?? 0)])->all();
        $sisa = fn (string $s) => (int) round((float) ($rows[$s]->sisa ?? 0));

        // pinjaman "berjalan" yang angsurannya sudah lewat jatuh tempo dihitung sebagai menunggak
        $lagging = Loan::query()->where('status', 'berjalan')->effectivelyOverdue()->selectRaw('COUNT(*) AS n, COALESCE(SUM(outstanding), 0) AS sisa')->first();
        $counts['berjalan'] -= (int) $lagging->n;
        $counts['menunggak'] += (int) $lagging->n;

        return ['counts' => $counts, 'outstanding' => $sisa('berjalan') + $sisa('menunggak'),
            'overdue_outstanding' => $sisa('menunggak') + (int) round((float) $lagging->sisa)];
    }

    /**
     * Tanda menunggak untuk sekumpulan pinjaman (satu query): per id → jumlah angsuran lewat jatuh tempo, nominal
     * tertunggak, jatuh tempo tertua, dan lama terlambat (hari). Pinjaman tanpa tunggakan tidak ada di hasil.
     *
     * @param  list<int>  $ids
     * @return array<int, array{late_count: int, late_amount: int, oldest_due: string, days_late: int}>
     */
    public static function overdueFlags(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $today = today();

        return \App\Support\LoanOverdue::scope(LoanInstallment::query()->whereIn('loan_id', $ids))
            ->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::ACTIVE))
            ->groupBy('loan_id')->selectRaw('loan_id, COUNT(*) AS n, SUM(amount - paid_amount) AS due, MIN(due_date) AS oldest')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->loan_id => [
                'late_count' => (int) $r->n, 'late_amount' => (int) round((float) $r->due),
                'oldest_due' => \Illuminate\Support\Carbon::parse($r->oldest)->toDateString(),
                'days_late' => (int) \Illuminate\Support\Carbon::parse($r->oldest)->startOfDay()->diffInDays($today->copy()->startOfDay()),
            ]])->all();
    }

    /**
     * Ringkasan lengkap untuk endpoint: jumlah & nominal per status, sebaran per metode bayar (pinjaman aktif),
     * dan angsuran yang jatuh tempo bulan ini / sudah lewat jatuh tempo.
     */
    public function summary(): array
    {
        $byStatus = Loan::query()->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(principal), 0) AS pokok, COALESCE(SUM(outstanding), 0) AS sisa')
            ->groupBy('status')->get()->keyBy('status');
        $statuses = collect(Loan::STATUSES)->map(fn ($label, $s) => [
            'status' => $s, 'label' => $label, 'count' => (int) ($byStatus[$s]->n ?? 0),
            'principal' => (int) round((float) ($byStatus[$s]->pokok ?? 0)), 'outstanding' => (int) round((float) ($byStatus[$s]->sisa ?? 0)),
        ])->values()->all();

        $byMethod = Loan::active()->selectRaw('payment_method, COUNT(*) AS n, COALESCE(SUM(outstanding), 0) AS sisa')->groupBy('payment_method')->get()->keyBy('payment_method');
        $methods = collect(Loan::METHODS)->map(fn ($label, $m) => [
            'method' => $m, 'label' => $label, 'count' => (int) ($byMethod[$m]->n ?? 0), 'outstanding' => (int) round((float) ($byMethod[$m]->sisa ?? 0)),
        ])->values()->all();

        $today = today();
        $open = LoanInstallment::query()->where('status', '!=', 'dibayar')->whereHas('loan', fn ($q) => $q->whereIn('status', Loan::ACTIVE));
        $sum = fn ($q) => (int) round((float) $q->sum(\Illuminate\Support\Facades\DB::raw('amount - paid_amount')));
        $dueMonth = (clone $open)->whereDayRange('due_date', $today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString());
        $late = \App\Support\LoanOverdue::scope(clone $open, $today);

        $stats = $this->stats();

        return [
            'counts' => $stats['counts'],
            'statuses' => $statuses,
            'methods' => $methods,
            'outstanding' => $stats['outstanding'],
            'overdue_outstanding' => $stats['overdue_outstanding'],
            'active_count' => $stats['counts']['berjalan'] + $stats['counts']['menunggak'],
            'awaiting_decision' => $stats['counts']['diajukan'],
            'awaiting_disbursement' => $stats['counts']['disetujui'],
            'due_this_month' => ['count' => (clone $dueMonth)->count(), 'amount' => $sum(clone $dueMonth)],
            'late_installments' => ['count' => (clone $late)->count(), 'amount' => $sum(clone $late)],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Rincian satu pinjaman: data pinjaman, pencairan, jadwal angsuran & ringkasan pembayaran.
     * Angsuran "belum" yang lewat jatuh tempo ditandai `late` (status tersimpan tetap apa adanya).
     */
    public function detail(Loan $loan): array
    {
        $loan->loadMissing(['member:id,member_code,name,class,type,phone,deleted_at', 'installments.user:id,name', 'disbursements.user:id,name', 'decider:id,name', 'canceller:id,name']);
        $today = now()->startOfDay();

        $installments = $loan->installments->map(fn (LoanInstallment $i) => [
            'id' => $i->id, 'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(),
            'amount' => (int) round((float) $i->amount), 'paid_amount' => (int) round((float) $i->paid_amount),
            'paid_at' => $i->paid_at?->toDateString(), 'payment_method' => $i->payment_method,
            'payment_label' => $i->payment_method ? (Loan::METHODS[$i->payment_method] ?? $i->payment_method) : null,
            'status' => $i->status, 'status_label' => LoanInstallment::STATUSES[$i->status] ?? $i->status,
            'remaining' => max(0, (int) round((float) $i->amount - (float) $i->paid_amount)),
            'late' => \App\Support\LoanOverdue::isLate($i, $today),
            'days_late' => \App\Support\LoanOverdue::daysLate($i, $today), 'note' => $i->note, 'user' => $i->user?->name,
        ])->all();

        $disbursements = $loan->disbursements->map(fn (LoanDisbursement $d) => [
            'id' => $d->id, 'disbursed_on' => $d->disbursed_on->toDateString(), 'amount' => (int) round((float) $d->amount),
            'proof' => $d->proof, 'has_file' => filled($d->proof_file), 'user' => $d->user?->name,
            'file_type' => $d->proof_file ? (strtolower(pathinfo($d->proof_file, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'image') : null,
            'recorded_at' => $d->created_at?->toDateTimeString(),
        ])->all();

        $paid = array_sum(array_column($installments, 'paid_amount'));
        $next = collect($installments)->first(fn ($i) => $i['status'] !== 'dibayar');

        return [
            'loan' => [...self::toArray($loan), 'member_phone' => $loan->member?->phone, 'member_deleted' => (bool) $loan->member?->trashed(), 'decided_at' => $loan->decided_at?->toDateTimeString(),
                'decided_by' => $loan->decider?->name, 'cancelled_by' => $loan->canceller?->name, 'paid_off_at' => $loan->paid_off_at?->toDateString()],
            'installments' => $installments,
            'disbursements' => $disbursements,
            'summary' => [
                'total' => $loan->totalDue(), 'paid' => $paid,
                'paid_count' => count(array_filter($installments, fn ($i) => $i['status'] === 'dibayar')),
                'count' => count($installments),
                'late_count' => count(array_filter($installments, fn ($i) => $i['late'])),
                'late_amount' => array_sum(array_map(fn ($i) => $i['late'] ? $i['remaining'] : 0, $installments)),
                'next' => $next,
                'progress' => $loan->totalDue() > 0 ? min(100, (int) floor($paid * 100 / $loan->totalDue())) : 0,
            ],
        ];
    }

    public static function toArray(Loan $l): array
    {
        $principal = (int) round((float) $l->principal);
        $interest = (int) round((float) $l->interest);

        return [
            'id' => $l->id, 'code' => $l->code,
            'member' => $l->member ? ['id' => $l->member->id, 'code' => $l->member->member_code, 'name' => $l->member->name, 'class' => $l->member->class, 'type' => $l->member->type] : null,
            'principal' => $principal, 'interest' => $interest, 'total' => $principal + $interest, 'tenor_months' => $l->tenor_months,
            'monthly' => $l->tenor_months ? (int) ceil(($principal + $interest) / $l->tenor_months) : 0,
            'payment_method' => $l->payment_method, 'payment_label' => Loan::METHODS[$l->payment_method] ?? $l->payment_method,
            'status' => $l->status, 'status_label' => Loan::STATUSES[$l->status] ?? $l->status,
            'outstanding' => (int) round((float) $l->outstanding), 'purpose' => $l->purpose, 'decision_note' => $l->decision_note,
            'cancel_reason' => $l->cancel_reason, 'cancelled_at' => $l->cancelled_at?->toDateTimeString(),
            'applied_at' => $l->applied_at?->toDateString(), 'disbursed_at' => $l->disbursed_at?->toDateString(),
        ];
    }
}
