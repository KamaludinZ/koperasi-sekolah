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
     * @param  array{search?: string, status?: string, method?: string}  $f
     */
    public function query(array $f = []): Builder
    {
        $term = mb_strtolower(trim($f['search'] ?? ''));

        return Loan::query()->with('member:id,member_code,name,class,type,deleted_at')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(code) LIKE ?', ["%{$term}%"])
                ->orWhereHas('member', fn ($m) => $m->withTrashed()->where(fn ($x) => $x->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(member_code) LIKE ?', ["%{$term}%"])))))
            ->when(($f['status'] ?? '') !== '', fn ($q) => $q->where('status', $f['status']))
            ->when(($f['method'] ?? '') !== '', fn ($q) => $q->where('payment_method', $f['method']))
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

        return ['counts' => $counts, 'outstanding' => $sisa('berjalan') + $sisa('menunggak'), 'overdue_outstanding' => $sisa('menunggak')];
    }

    /**
     * Rincian satu pinjaman: data pinjaman, pencairan, jadwal angsuran & ringkasan pembayaran.
     * Angsuran "belum" yang lewat jatuh tempo ditandai `late` (status tersimpan tetap apa adanya).
     */
    public function detail(Loan $loan): array
    {
        $loan->loadMissing(['member:id,member_code,name,class,type,phone,deleted_at', 'installments.user:id,name', 'disbursements.user:id,name', 'decider:id,name']);
        $today = now()->startOfDay();

        $installments = $loan->installments->map(fn (LoanInstallment $i) => [
            'id' => $i->id, 'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(),
            'amount' => (int) round((float) $i->amount), 'paid_amount' => (int) round((float) $i->paid_amount),
            'paid_at' => $i->paid_at?->toDateString(), 'payment_method' => $i->payment_method,
            'payment_label' => $i->payment_method ? (Loan::METHODS[$i->payment_method] ?? $i->payment_method) : null,
            'status' => $i->status, 'status_label' => LoanInstallment::STATUSES[$i->status] ?? $i->status,
            'late' => $i->status !== 'dibayar' && $i->due_date->lt($today), 'note' => $i->note, 'user' => $i->user?->name,
        ])->all();

        $disbursements = $loan->disbursements->map(fn (LoanDisbursement $d) => [
            'id' => $d->id, 'disbursed_on' => $d->disbursed_on->toDateString(), 'amount' => (int) round((float) $d->amount),
            'proof' => $d->proof, 'has_file' => filled($d->proof_file), 'user' => $d->user?->name,
        ])->all();

        $paid = array_sum(array_column($installments, 'paid_amount'));
        $next = collect($installments)->first(fn ($i) => $i['status'] !== 'dibayar');

        return [
            'loan' => [...self::toArray($loan), 'member_phone' => $loan->member?->phone, 'decided_at' => $loan->decided_at?->toDateTimeString(),
                'decided_by' => $loan->decider?->name, 'paid_off_at' => $loan->paid_off_at?->toDateString()],
            'installments' => $installments,
            'disbursements' => $disbursements,
            'summary' => [
                'total' => $loan->totalDue(), 'paid' => $paid,
                'paid_count' => count(array_filter($installments, fn ($i) => $i['status'] === 'dibayar')),
                'count' => count($installments),
                'late_count' => count(array_filter($installments, fn ($i) => $i['late'])),
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
            'applied_at' => $l->applied_at?->toDateString(), 'disbursed_at' => $l->disbursed_at?->toDateString(),
        ];
    }
}
