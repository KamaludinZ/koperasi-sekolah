<?php

namespace App\Services;

use App\Models\Loan;
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
