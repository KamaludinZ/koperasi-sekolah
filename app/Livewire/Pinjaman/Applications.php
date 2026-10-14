<?php

namespace App\Livewire\Pinjaman;

use App\Models\Loan;
use App\Services\LoanService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Pengajuan (Admin & Pengurus): daftar pengajuan pinjaman per tahap keputusan —
 * menunggu keputusan, disetujui (belum cair), ditolak, dibatalkan. Pengajuan terbaru di atas.
 */
#[Layout('components.layouts.app')]
#[Title('Pengajuan Pinjaman')]
class Applications extends Component
{
    use WithPagination;

    /** Tab tahap pengajuan → status pinjaman. */
    public const TABS = ['diajukan' => 'Menunggu keputusan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan'];

    public const PER_PAGE = 20;

    #[Url(as: 'tahap', except: 'diajukan')]
    public string $tab = 'diajukan';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'diajukan';
        $this->resetPage();
    }

    #[Computed]
    public function applications()
    {
        $status = array_key_exists($this->tab, self::TABS) ? $this->tab : 'diajukan';

        return app(LoanService::class)->query(['search' => mb_substr($this->search, 0, 100), 'status' => $status])
            ->paginate(self::PER_PAGE)->through(fn (Loan $l) => LoanService::toArray($l));
    }

    /** @return array<string, int> jumlah per tab */
    #[Computed]
    public function counts(): array
    {
        $counts = app(LoanService::class)->stats()['counts'];

        return collect(self::TABS)->map(fn ($label, $status) => $counts[$status] ?? 0)->all();
    }

    public function render()
    {
        return view('livewire.pinjaman.applications');
    }
}
