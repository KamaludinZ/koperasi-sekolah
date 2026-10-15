<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Riwayat pencairan & angsuran (bagian Jejak audit): semua dana keluar (pencairan) dan masuk (pembayaran angsuran),
 * TERMASUK pembayaran yang dikoreksi — tetap tampil dengan alasan, petugas pengoreksi, dan pembayaran penggantinya —
 * berurutan terbaru dulu. Saring jenis (pencairan|angsuran|koreksi), periode, pinjaman, dan kata kunci.
 */
class LoanLedgerQuery
{
    public const TYPES = ['pencairan' => 'Pencairan', 'angsuran' => 'Angsuran', 'koreksi' => 'Dikoreksi / pengganti'];

    /** Batas baris yang diambil per sumber (pencairan, pembayaran) sebelum digabung. */
    public const MAX_ROWS = 2000;

    /** @param  array{type?: string, from?: ?Carbon, to?: ?Carbon, loan_id?: ?int, search?: string}  $f */
    public function rows(array $f = []): Collection
    {
        $type = array_key_exists($f['type'] ?? '', self::TYPES) ? $f['type'] : '';
        $term = mb_strtolower(trim(mb_substr($f['search'] ?? '', 0, 100)));
        $loanFilter = function ($q) use ($f, $term) {
            $q->when(! empty($f['loan_id']), fn ($w) => $w->where('loan_id', (int) $f['loan_id']))
                ->when($term !== '', fn ($w) => $w->whereHas('loan', fn ($l) => $l->where(fn ($x) => $x
                    ->whereRaw("LOWER(code) LIKE ? ESCAPE '\\'", ['%'.self::escape($term).'%'])
                    ->orWhereHas('member', fn ($m) => $m->withTrashed()->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", ['%'.self::escape($term).'%'])))));
        };
        $from = $f['from'] ?? null;
        $to = $f['to'] ?? null;
        $range = fn ($q, string $col) => $q->when($from, fn ($w) => $w->where($col, '>=', $from->toDateString()))
            ->when($to, fn ($w) => $w->where($col, '<', $to->copy()->addDay()->toDateString()));
        $loanCols = 'loan:id,code,member_id';
        $memberCols = 'loan.member:id,member_code,name,deleted_at';
        $rows = collect();

        if ($type === '' || $type === 'pencairan') {
            $q = LoanDisbursement::query()->with($loanCols, $memberCols, 'user:id,name')->tap($loanFilter);
            $rows = $rows->merge($range($q, 'disbursed_on')->orderByDesc('disbursed_on')->orderByDesc('id')->limit(self::MAX_ROWS)->get()
                ->map(fn (LoanDisbursement $d) => self::row('pencairan', 'd'.$d->id, $d->disbursed_on, $d->created_at, $d->loan, -(int) round((float) $d->amount),
                    ['label' => 'Pencairan dana', 'method' => null, 'reference' => $d->proof, 'user' => $d->user?->name, 'valid' => true])));
        }
        if ($type !== 'pencairan') {
            $q = LoanPayment::query()->with($loanCols, $memberCols, 'user:id,name', 'voider:id,name')->tap($loanFilter)
                ->when($type === 'angsuran', fn ($w) => $w->whereNull('voided_at')->whereNull('replaces_id'))
                ->when($type === 'koreksi', fn ($w) => $w->where(fn ($x) => $x->whereNotNull('voided_at')->orWhereNotNull('replaces_id')));
            $rows = $rows->merge($range($q, 'paid_at')->reorder()->orderByDesc('paid_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get()
                ->map(fn (LoanPayment $p) => self::row($p->voided_at ? 'dibatalkan' : ($p->replaces_id ? 'pengganti' : 'angsuran'), 'p'.$p->id, $p->paid_at, $p->created_at, $p->loan,
                    (int) round((float) $p->amount), [
                        'label' => $p->voided_at ? 'Angsuran dikoreksi (batal)' : ($p->replaces_id ? 'Angsuran pengganti' : 'Angsuran'),
                        'method' => Loan::METHODS[$p->payment_method] ?? $p->payment_method,
                        'reference' => LoanPaymentService::referenceLabel($p->payment_method, $p->reference),
                        'sequences' => array_column($p->allocations ?? [], 'sequence'), 'user' => $p->user?->name, 'valid' => $p->voided_at === null,
                        'void' => $p->voided_at ? ['at' => $p->voided_at->toDateTimeString(), 'by' => $p->voider?->name, 'reason' => $p->void_reason] : null,
                        'replaces_id' => $p->replaces_id, 'payment_id' => $p->id,
                    ])));
        }

        return $rows->sortByDesc(fn ($r) => $r['date'].' '.$r['recorded_at'].' '.$r['key'])->values();
    }

    /** Total dana keluar & masuk (hanya yang sah) dari baris. */
    public static function totals(Collection $rows): array
    {
        return [
            'disbursed' => (int) -$rows->where('type', 'pencairan')->sum('amount'),
            'collected' => (int) $rows->whereIn('type', ['angsuran', 'pengganti'])->sum('amount'),
            'voided' => (int) $rows->where('type', 'dibatalkan')->sum('amount'),
            'voided_count' => $rows->where('type', 'dibatalkan')->count(),
        ];
    }

    private static function row(string $type, string $key, $date, $recorded, ?Loan $loan, int $amount, array $extra): array
    {
        return [
            'key' => $key, 'type' => $type, 'date' => $date?->toDateString(), 'recorded_at' => $recorded?->toDateTimeString(), 'amount' => $amount,
            'loan' => $loan ? ['id' => $loan->id, 'code' => $loan->code, 'member' => $loan->member?->name, 'member_code' => $loan->member?->member_code] : null,
        ] + $extra + ['sequences' => [], 'void' => null, 'replaces_id' => null, 'payment_id' => null];
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
