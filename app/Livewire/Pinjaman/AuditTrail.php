<?php

namespace App\Livewire\Pinjaman;

use App\Models\ActivityLog;
use App\Services\AuditQuery;
use App\Services\LoanAuditQuery;
use App\Services\LoanLedgerQuery;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Jejak audit (Admin & Pengurus): seluruh peristiwa pinjaman — pengajuan, pencairan & bukti, angsuran &
 * koreksi, tunggakan, unduhan rekap — dengan saringan kelompok, periode, petugas, pinjaman, kata kunci, serta panel
 * rincian (nilai sebelum/sesudah, alasan, IP). Tampilan kedua "Pencairan & angsuran": riwayat dana keluar/masuk termasuk
 * pembayaran yang dikoreksi beserta alasan & penggantinya.
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

    /** Tampilan: jejak (peristiwa audit) | transaksi (riwayat pencairan & angsuran). */
    #[Url(as: 'tampil', except: 'jejak')]
    public string $view = 'jejak';

    #[Url(as: 'jenis', except: '')]
    public string $ledgerType = '';

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
        $this->view = $this->view === 'transaksi' ? 'transaksi' : 'jejak';
        $this->ledgerType = array_key_exists($this->ledgerType, LoanLedgerQuery::TYPES) ? $this->ledgerType : '';
    }

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->selectedId = null;
        $this->sanitize();
        $this->resetPage();
    }

    public function setGroup(string $group): void
    {
        $this->group = $this->group === $group ? '' : $group;
        $this->sanitize();
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('group', 'period', 'actor', 'loanId', 'search', 'ledgerType');
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

    /** Riwayat pencairan & angsuran (termasuk yang dikoreksi) untuk tampilan "transaksi". */
    #[Computed]
    public function ledgerAll()
    {
        [$from, $to] = $this->range;

        return app(LoanLedgerQuery::class)->rows(['type' => $this->ledgerType, 'from' => $from, 'to' => $to, 'loan_id' => $this->loanId ?: null, 'search' => $this->search]);
    }

    #[Computed]
    public function ledger(): \Illuminate\Pagination\LengthAwarePaginator
    {
        $rows = $this->ledgerAll;
        $page = max(1, min($this->getPage(), (int) ceil(max(1, $rows->count()) / self::PER_PAGE)));

        return new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page);
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

    /** Unduh CSV tampilan yang sedang dilihat (sesuai saringan, maks. 20.000 baris); unduhan dicatat di jejak audit. */
    public function export()
    {
        [$from, $to] = $this->range;
        $filters = ['view' => $this->view, 'group' => $this->group, 'ledger_type' => $this->ledgerType, 'actor' => $this->actor, 'loan_id' => $this->loanId ?: null,
            'search' => $this->search, 'from' => $from?->toDateString(), 'to' => $to?->toDateString()];
        \App\Services\AuditLog::record('pinjaman.audit_diekspor', 'pinjaman',
            'Ekspor jejak audit pinjaman ('.($this->view === 'transaksi' ? 'pencairan & angsuran' : 'jejak peristiwa').')', null, $filters);

        $exporter = app(\App\Services\LoanAuditExport::class);
        $rows = $this->view === 'transaksi'
            ? $exporter->ledgerRows($this->ledgerAll)
            : $exporter->eventRows(app(LoanAuditQuery::class)->query(['group' => $this->group === 'perhatian' ? '' : $this->group, 'from' => $from, 'to' => $to,
                'actor' => $this->actor, 'loan_id' => $this->loanId ?: null, 'search' => $this->search])
                ->when($this->group === 'perhatian', fn ($q) => $q->whereIn('event', LoanAuditQuery::NOTABLE)));
        $name = ($this->view === 'transaksi' ? 'riwayat-pencairan-angsuran-' : 'jejak-audit-pinjaman-').now()->format('Ymd-His').'.csv';

        $file = \App\Services\LoanAuditExport::build($rows); // isi ditandatangani HMAC (header X-Audit-Signature)

        return response()->streamDownload(fn () => print($file['content']), $name, ['Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff', 'X-Audit-Signature' => $file['signature'], 'X-Audit-Rows' => (string) $file['rows']]);
    }

    /** Hasil pemeriksaan rantai HMAC terakhir (untuk spanduk status). */
    #[Computed]
    public function integrity(): ?array
    {
        return \App\Services\LoanAuditGuard::last();
    }

    /** Periksa keaslian rantai jejak audit sekarang. */
    public function verifyChain(): void
    {
        $r = app(\App\Services\LoanAuditGuard::class)->check();
        unset($this->integrity, $this->logs, $this->counts);
        $this->dispatch('toast', type: $r['valid'] ? 'success' : 'error',
            message: $r['valid'] ? "Rantai jejak audit utuh ({$r['checked']} mata rantai)." : 'Rantai tidak utuh: '.\App\Services\LoanAuditGuard::describe($r).'.');
    }

    public function render()
    {
        return view('livewire.pinjaman.audit-trail');
    }
}
