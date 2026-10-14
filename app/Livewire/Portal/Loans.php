<?php

namespace App\Livewire\Portal;

use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Portal anggota → Pinjaman Saya: pinjaman atas nama anggota yang sedang masuk (guard `member`) beserta status,
 * sisa, dan angsuran berikutnya. Tidak ada parameter id — data orang lain tidak bisa diminta.
 */
#[Layout('components.layouts.portal')]
#[Title('Pinjaman Saya')]
class Loans extends Component
{
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
