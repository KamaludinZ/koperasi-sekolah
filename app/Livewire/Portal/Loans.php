<?php

namespace App\Livewire\Portal;

use App\Models\Loan;
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

        return Loan::query()->where('member_id', $member->id)->with(['installments' => fn ($q) => $q->where('status', '!=', 'dibayar')->orderBy('sequence')])
            ->orderByRaw("CASE status WHEN 'menunggak' THEN 0 WHEN 'berjalan' THEN 1 WHEN 'disetujui' THEN 2 WHEN 'diajukan' THEN 3 ELSE 4 END")
            ->orderByDesc('applied_at')->orderByDesc('id')->get()
            ->map(function (Loan $l) use ($today) {
                $next = $l->installments->first();
                $paid = $l->totalDue() - (int) round((float) $l->outstanding);

                return LoanService::toArray($l) + [
                    'progress' => in_array($l->status, [...Loan::ACTIVE, 'lunas'], true) && $l->totalDue() > 0 ? min(100, intdiv(max(0, $paid) * 100, $l->totalDue())) : null,
                    'next' => $next ? ['sequence' => $next->sequence, 'due_date' => $next->due_date->toDateString(),
                        'remaining' => (int) round((float) $next->amount - (float) $next->paid_amount), 'late' => $next->due_date->lt($today)] : null,
                    'late_count' => $l->installments->filter(fn ($i) => $i->due_date->lt($today))->count(),
                    'paid_off_at' => $l->paid_off_at?->toDateString(),
                ];
            })->all();
    }

    #[Computed]
    public function totals(): array
    {
        $active = collect($this->loans)->whereIn('status', Loan::ACTIVE);

        return ['active' => $active->count(), 'outstanding' => (int) $active->sum('outstanding')];
    }

    public function render()
    {
        return view('livewire.portal.loans');
    }
}
