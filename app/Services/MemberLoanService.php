<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Member;
use App\Support\LoanOverdue;

/**
 * Data pinjaman untuk portal anggota (halaman "Pinjaman saya" & GET /portal/pinjaman/data): hanya pinjaman milik
 * anggota itu, tanpa data internal petugas (nama petugas, catatan internal, pembayaran yang dikoreksi).
 */
class MemberLoanService
{
    /** Tab saringan daftar: status yang masuk tiap tab (null = semua) dan pesan bila kosong. */
    public const TABS = [
        'semua' => ['label' => 'Semua', 'statuses' => null, 'empty' => 'Belum ada pinjaman.'],
        'berjalan' => ['label' => 'Berjalan', 'statuses' => Loan::ACTIVE, 'empty' => 'Tidak ada pinjaman yang sedang berjalan.'],
        'pengajuan' => ['label' => 'Pengajuan', 'statuses' => ['diajukan', 'disetujui'], 'empty' => 'Tidak ada pengajuan yang sedang diproses.'],
        'selesai' => ['label' => 'Selesai', 'statuses' => ['lunas', 'ditolak', 'dibatalkan'], 'empty' => 'Belum ada pinjaman yang selesai.'],
    ];

    public function __construct(private LoanPaymentService $payments) {}

    /** @return list<array> pinjaman aktif & pengajuan dulu, lalu yang selesai; terbaru dulu */
    public function forMember(Member $member): array
    {
        $today = today();
        $payments = $this->payments;

        return Loan::query()->ownedBy($member)->with(['installments' => fn ($q) => $q->where('status', '!=', 'dibayar')->orderBy('sequence')])
            ->orderByRaw("CASE status WHEN 'menunggak' THEN 0 WHEN 'berjalan' THEN 1 WHEN 'disetujui' THEN 2 WHEN 'diajukan' THEN 3 ELSE 4 END")
            ->orderByDesc('applied_at')->orderByDesc('id')->get()
            ->map(function (Loan $l) use ($today, $payments) {
                $next = $l->installments->first();
                $late = in_array($l->status, Loan::ACTIVE, true) ? $l->installments->filter(fn ($i) => LoanOverdue::isLate($i, $today)) : collect();
                $paid = $l->totalDue() - (int) round((float) $l->outstanding);

                return array_diff_key(LoanService::toArray($l->setRelation('member', null)), ['member' => true]) + [
                    'progress' => in_array($l->status, [...Loan::ACTIVE, 'lunas'], true) && $l->totalDue() > 0 ? min(100, intdiv(max(0, $paid) * 100, $l->totalDue())) : null,
                    'next' => $next ? ['sequence' => $next->sequence, 'due_date' => $next->due_date->toDateString(),
                        'remaining' => (int) round((float) $next->amount - (float) $next->paid_amount), 'late' => LoanOverdue::isLate($next, $today)] : null,
                    'late_count' => $late->count(),
                    'late_amount' => (int) $late->sum(fn ($i) => (int) round((float) $i->amount - (float) $i->paid_amount)),
                    // menunggak bila status sudah ditandai ATAU ada angsuran lewat jatuh tempo yang belum tercatat statusnya
                    'overdue' => $l->status === 'menunggak' || $late->isNotEmpty(),
                    'late_since' => $late->first()?->due_date->toDateString(),
                    'days_late' => $late->isNotEmpty() ? LoanOverdue::daysLate($late->first(), $today) : 0,
                    'balance' => in_array($l->status, Loan::ACTIVE, true) ? $payments->balance($l) : null,
                    'payments' => $l->disbursed_at ? self::memberHistory($payments->history($l)) : [],
                    'paid_off_at' => $l->paid_off_at?->toDateString(),
                ];
            })->sortBy(fn ($l) => $l['overdue'] ? 0 : 1)->values()->all();
    }

    /** Jumlah pinjaman anggota yang punya angsuran lewat jatuh tempo (atau berstatus menunggak) — untuk tanda di navigasi. */
    public static function overdueCount(int $memberId): int
    {

        return Loan::query()->ownedBy($memberId)->whereIn('status', Loan::ACTIVE)
            ->where(fn ($q) => $q->where('status', 'menunggak')
                ->orWhereHas('installments', fn ($i) => LoanOverdue::scope($i)))
            ->count();
    }

    /**
     * Riwayat untuk anggota: hanya pembayaran sah (yang dikoreksi petugas tidak ditampilkan), tanpa nama petugas
     * maupun catatan internal. Terbaru dulu.
     *
     * @return list<array{number: int, paid_at: string, amount: int, payment_label: string, reference_label: ?string, sequences: list<int>, balance_after: int}>
     */
    public static function memberHistory(array $rows): array
    {
        return array_reverse(array_values(array_map(fn ($r) => [
            'number' => $r['number'], 'paid_at' => $r['paid_at'], 'amount' => $r['amount'], 'payment_label' => $r['payment_label'],
            'reference_label' => $r['reference_label'], 'sequences' => $r['sequences'], 'balance_after' => $r['balance_after'],
        ], array_filter($rows, fn ($r) => ! $r['voided']))));
    }

    /** Ringkasan untuk kartu atas dari hasil forMember(). */
    public static function totals(array $loans): array
    {
        $active = collect($loans)->whereIn('status', Loan::ACTIVE);

        $next = $active->filter(fn ($l) => $l['next'])->sortBy(fn ($l) => $l['next']['due_date'].sprintf('%010d', $l['id']))->first();

        $overdue = $active->where('overdue', true);

        return ['overdue' => $overdue->count(), 'late_since' => $overdue->pluck('late_since')->filter()->min(), 'days_late' => (int) $overdue->max('days_late'),
            'active' => $active->count(), 'outstanding' => (int) $active->sum('outstanding'), 'late_amount' => (int) $active->sum('late_amount'),
            'next' => $next ? $next['next'] + ['code' => $next['code']] : null];
    }

    /** @return list<array> pinjaman yang lolos tab */
    public static function filter(array $loans, string $tab): array
    {
        $statuses = self::TABS[$tab]['statuses'] ?? null;

        return $statuses === null ? $loans : array_values(array_filter($loans, fn ($l) => in_array($l['status'], $statuses, true)));
    }

    /** @return array<string,int> jumlah pinjaman per tab */
    public static function counts(array $loans): array
    {
        $out = [];
        foreach (array_keys(self::TABS) as $tab) {
            $out[$tab] = count(self::filter($loans, $tab));
        }

        return $out;
    }

    /**
     * Ringkasan sisa & angsuran berikutnya untuk anggota: total sisa pinjaman berjalan, angsuran terdekat lintas
     * pinjaman, tunggakan, tagihan bulan ini, dan per pinjaman aktif: sisa pokok/bunga, angsuran berikutnya, serta
     * jadwal angsuran yang belum lunas (nominal sisa per angsuran, jatuh tempo, terlambat berapa hari).
     */
    public function outstanding(Member $member): array
    {
        $today = today();
        $loans = Loan::query()->ownedBy($member)->whereIn('status', Loan::ACTIVE)
            ->with(['installments' => fn ($q) => $q->where('status', '!=', 'dibayar')->orderBy('sequence')])
            ->orderBy('applied_at')->orderBy('id')->get();

        $rows = $loans->map(function (Loan $l) use ($today) {
            $schedule = $l->installments->map(fn ($i) => [
                'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(),
                'amount' => (int) round((float) $i->amount), 'remaining' => (int) round((float) $i->amount - (float) $i->paid_amount),
                'late' => LoanOverdue::isLate($i, $today), 'days_late' => LoanOverdue::daysLate($i, $today),
            ])->values();
            $b = $this->payments->balance($l);

            return [
                'id' => $l->id, 'code' => $l->code, 'status' => $l->status, 'status_label' => Loan::STATUSES[$l->status] ?? $l->status,
                'payment_method' => $l->payment_method, 'payment_label' => Loan::METHODS[$l->payment_method] ?? $l->payment_method,
                'total' => $l->totalDue(), 'outstanding' => (int) round((float) $l->outstanding),
                'principal_remaining' => $b['principal_remaining'], 'interest_remaining' => $b['interest_remaining'],
                'installments_paid' => $b['installments_paid'], 'installments' => $b['installments'], 'progress' => $b['progress'],
                'overdue' => $l->status === 'menunggak' || $schedule->contains('late', true),
                'late_amount' => (int) $schedule->where('late', true)->sum('remaining'),
                'next' => $schedule->first(), 'schedule' => $schedule->all(),
            ];
        });

        $upcoming = $rows->filter(fn ($r) => $r['next'])->sortBy(fn ($r) => $r['next']['due_date'].sprintf('%010d', $r['id']))->first();
        $monthEnd = $today->copy()->endOfMonth()->toDateString();

        return [
            'loans' => $rows->values()->all(),
            'totals' => [
                'active' => $rows->count(), 'outstanding' => (int) $rows->sum('outstanding'),
                'principal_remaining' => (int) $rows->sum('principal_remaining'), 'interest_remaining' => (int) $rows->sum('interest_remaining'),
                'late_amount' => (int) $rows->sum('late_amount'), 'overdue_loans' => $rows->where('overdue', true)->count(),
                // yang perlu dibayar sampai akhir bulan ini: tunggakan + jatuh tempo bulan ini
                'due_this_month' => (int) $rows->sum(fn ($r) => collect($r['schedule'])->where('due_date', '<=', $monthEnd)->sum('remaining')),
                'next' => $upcoming ? $upcoming['next'] + ['code' => $upcoming['code'], 'loan_id' => $upcoming['id']] : null,
            ],
        ];
    }

    /**
     * Riwayat angsuran satu pinjaman untuk pemiliknya (pemanggil wajib memastikan pinjaman milik anggota): pembayaran
     * sah terbaru dulu, total per metode, ringkasan sisa pokok/bunga, dan status tiap angsuran. Pinjaman yang belum
     * cair mengembalikan riwayat kosong.
     */
    public function history(Loan $loan): array
    {
        $today = today();
        $rows = $loan->disbursed_at ? self::memberHistory($this->payments->history($loan)) : [];
        $balance = $loan->disbursed_at ? $this->payments->balance($loan) : null;

        return [
            'loan' => ['id' => $loan->id, 'code' => $loan->code, 'status' => $loan->status, 'status_label' => Loan::STATUSES[$loan->status] ?? $loan->status,
                'principal' => (int) round((float) $loan->principal), 'interest' => (int) round((float) $loan->interest), 'total' => $loan->totalDue(),
                'tenor_months' => $loan->tenor_months, 'outstanding' => (int) round((float) $loan->outstanding),
                'disbursed_at' => $loan->disbursed_at?->toDateString(), 'paid_off_at' => $loan->paid_off_at?->toDateString()],
            'payments' => $rows,
            'by_method' => collect($rows)->groupBy('payment_label')->map(fn ($g, $label) => ['label' => $label, 'count' => $g->count(), 'amount' => (int) $g->sum('amount')])->values()->all(),
            'paid_total' => (int) collect($rows)->sum('amount'),
            'balance' => $balance ? array_diff_key($balance, ['last_payment' => true]) : null,
            'installments' => $loan->installments()->get()->map(fn ($i) => [
                'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(), 'amount' => (int) round((float) $i->amount),
                'paid_amount' => (int) round((float) $i->paid_amount), 'paid_at' => $i->paid_at?->toDateString(),
                'status' => LoanOverdue::status($i, $today),
                'status_label' => \App\Models\LoanInstallment::STATUSES[LoanOverdue::status($i, $today)],
            ])->all(),
        ];
    }

    /** Pinjaman milik anggota; milik orang lain atau tidak ada sama-sama 404 (keberadaannya tidak dibocorkan). */
    public static function ownedLoan(Member $member, int|string $id): Loan
    {
        abort_unless(ctype_digit((string) $id), 404);

        return Loan::query()->ownedBy($member)->whereKey((int) $id)->firstOr(fn () => abort(404));
    }
}
