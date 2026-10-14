<?php

namespace App\Livewire\Portal;

use App\Models\Loan;
use App\Services\LoanPaymentService;
use App\Services\LoanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal anggota → Pinjaman Saya: pinjaman atas nama anggota yang sedang masuk (guard `member`) beserta status,
 * sisa, dan angsuran berikutnya. Tidak ada parameter id — data orang lain tidak bisa diminta.
 */
#[Layout('components.layouts.portal')]
#[Title('Pinjaman Saya')]
class Loans extends Component
{
    /** Tab saringan daftar: status yang masuk tiap tab (null = semua) dan pesan bila kosong. */
    public const TABS = [
        'semua' => ['label' => 'Semua', 'statuses' => null, 'empty' => 'Belum ada pinjaman.'],
        'berjalan' => ['label' => 'Berjalan', 'statuses' => Loan::ACTIVE, 'empty' => 'Tidak ada pinjaman yang sedang berjalan.'],
        'pengajuan' => ['label' => 'Pengajuan', 'statuses' => ['diajukan', 'disetujui'], 'empty' => 'Tidak ada pengajuan yang sedang diproses.'],
        'selesai' => ['label' => 'Selesai', 'statuses' => ['lunas', 'ditolak', 'dibatalkan'], 'empty' => 'Belum ada pinjaman yang selesai.'],
    ];

    #[Url(as: 'tampil', except: 'semua')]
    public string $tab = 'semua';

    public function mount(): void
    {
        $this->setTab($this->tab);
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'semua';
    }

    /** @return list<array> pinjaman yang lolos tab aktif */
    #[Computed]
    public function shown(): array
    {
        $statuses = self::TABS[$this->tab]['statuses'] ?? null;

        return $statuses === null ? $this->loans : array_values(array_filter($this->loans, fn ($l) => in_array($l['status'], $statuses, true)));
    }

    /** @return array<string,int> jumlah pinjaman per tab */
    #[Computed]
    public function counts(): array
    {
        return collect(self::TABS)->map(fn ($t) => $t['statuses'] === null ? count($this->loans)
            : collect($this->loans)->whereIn('status', $t['statuses'])->count())->all();
    }

    /** @return list<array> pinjaman aktif & pengajuan dulu, lalu yang selesai; terbaru dulu */
    #[Computed]
    public function loans(): array
    {
        $member = Auth::guard('member')->user();
        abort_unless($member, 401);
        $today = today();
        $payments = app(LoanPaymentService::class);

        return Loan::query()->where('member_id', $member->id)->with(['installments' => fn ($q) => $q->where('status', '!=', 'dibayar')->orderBy('sequence')])
            ->orderByRaw("CASE status WHEN 'menunggak' THEN 0 WHEN 'berjalan' THEN 1 WHEN 'disetujui' THEN 2 WHEN 'diajukan' THEN 3 ELSE 4 END")
            ->orderByDesc('applied_at')->orderByDesc('id')->get()
            ->map(function (Loan $l) use ($today, $payments) {
                $next = $l->installments->first();
                $paid = $l->totalDue() - (int) round((float) $l->outstanding);

                return LoanService::toArray($l) + [
                    'progress' => in_array($l->status, [...Loan::ACTIVE, 'lunas'], true) && $l->totalDue() > 0 ? min(100, intdiv(max(0, $paid) * 100, $l->totalDue())) : null,
                    'next' => $next ? ['sequence' => $next->sequence, 'due_date' => $next->due_date->toDateString(),
                        'remaining' => (int) round((float) $next->amount - (float) $next->paid_amount), 'late' => $next->due_date->lt($today)] : null,
                    'late_count' => $l->installments->filter(fn ($i) => $i->due_date->lt($today))->count(),
                    'late_amount' => (int) $l->installments->filter(fn ($i) => $i->due_date->lt($today))->sum(fn ($i) => (int) round((float) $i->amount - (float) $i->paid_amount)),
                    'balance' => in_array($l->status, Loan::ACTIVE, true) ? $payments->balance($l) : null,
                    'payments' => $l->disbursed_at ? self::memberHistory($payments->history($l)) : [],
                    'paid_off_at' => $l->paid_off_at?->toDateString(),
                ];
            })->all();
    }

    /**
     * Riwayat untuk anggota: hanya pembayaran sah (yang dikoreksi petugas tidak ditampilkan), tanpa nama petugas
     * maupun catatan internal. Terbaru dulu.
     *
     * @return list<array{number: int, paid_at: string, amount: int, payment_label: string, reference_label: ?string, sequences: list<int>, balance_after: int}>
     */
    private static function memberHistory(array $rows): array
    {
        return array_reverse(array_values(array_map(fn ($r) => [
            'number' => $r['number'], 'paid_at' => $r['paid_at'], 'amount' => $r['amount'], 'payment_label' => $r['payment_label'],
            'reference_label' => $r['reference_label'], 'sequences' => $r['sequences'], 'balance_after' => $r['balance_after'],
        ], array_filter($rows, fn ($r) => ! $r['voided']))));
    }

    #[Computed]
    public function totals(): array
    {
        $active = collect($this->loans)->whereIn('status', Loan::ACTIVE);

        $next = $active->filter(fn ($l) => $l['next'])->sortBy(fn ($l) => $l['next']['due_date'].sprintf('%010d', $l['id']))->first();

        return ['active' => $active->count(), 'outstanding' => (int) $active->sum('outstanding'), 'late_amount' => (int) $active->sum('late_amount'),
            'next' => $next ? $next['next'] + ['code' => $next['code']] : null];
    }

    public function render()
    {
        return view('livewire.portal.loans');
    }
}
