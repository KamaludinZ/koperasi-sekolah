<?php

namespace App\Livewire\Pinjaman;

use App\Models\ActivityLog;
use App\Services\AuditQuery;
use App\Services\LoanAuditQuery;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Jejak audit (Admin & Pengurus): seluruh peristiwa pinjaman — pengajuan, pencairan & bukti, angsuran &
 * koreksi, tunggakan, unduhan rekap — dengan saringan kelompok, periode, petugas, pinjaman, kata kunci, serta panel
 * rincian (nilai sebelum/sesudah, alasan, IP).
 */
#[Layout('components.layouts.app')]
#[Title('Jejak Audit Pinjaman')]
class AuditTrail extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Url(as: 'kelompok', except: '')]
    public string $group = '';

    #[Url(as: 'periode', except: '30-hari')]
    public string $period = '30-hari';

    #[Url(as: 'petugas', except: '')]
    public string $actor = '';

    #[Url(as: 'pinjaman', except: 0)]
    public int $loanId = 0;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->sanitize();
    }

    public function updated(): void
    {
        $this->sanitize();
        $this->resetPage();
    }

    private function sanitize(): void
    {
        $this->group = array_key_exists($this->group, LoanAuditQuery::GROUPS) || $this->group === 'perhatian' ? $this->group : '';
        $this->period = array_key_exists($this->period, AuditQuery::PERIODS) ? $this->period : '30-hari';
        $this->actor = mb_substr($this->actor, 0, 100);
        $this->search = mb_substr($this->search, 0, 100);
        $this->loanId = max(0, $this->loanId);
    }

    public function setGroup(string $group): void
    {
        $this->group = $this->group === $group ? '' : $group;
        $this->sanitize();
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('group', 'period', 'actor', 'loanId', 'search');
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->selectedId = ActivityLog::where('category', 'pinjaman')->whereKey($id)->exists() ? $id : null;
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    #[Computed]
    public function range(): array
    {
        return AuditQuery::range($this->period);
    }

    #[Computed]
    public function logs()
    {
        [$from, $to] = $this->range;
        $query = app(LoanAuditQuery::class)->query(['group' => $this->group === 'perhatian' ? '' : $this->group, 'from' => $from, 'to' => $to,
            'actor' => $this->actor, 'loan_id' => $this->loanId ?: null, 'search' => $this->search])
            ->when($this->group === 'perhatian', fn ($q) => $q->whereIn('event', LoanAuditQuery::NOTABLE));

        return $query->paginate(self::PER_PAGE)->through(fn (ActivityLog $l) => LoanAuditQuery::toArray($l));
    }

    #[Computed]
    public function counts(): array
    {
        return app(LoanAuditQuery::class)->counts(...$this->range);
    }

    #[Computed]
    public function actors(): array
    {
        return app(LoanAuditQuery::class)->actors();
    }

    #[Computed]
    public function selected(): ?array
    {
        $log = $this->selectedId ? ActivityLog::where('category', 'pinjaman')->find($this->selectedId) : null;

        return $log ? LoanAuditQuery::toArray($log) : null;
    }

    #[Computed]
    public function loanLabel(): ?string
    {
        return $this->loanId ? \App\Models\Loan::whereKey($this->loanId)->value('code') : null;
    }

    public function render()
    {
        return view('livewire.pinjaman.audit-trail');
    }
}
