<?php

namespace App\Livewire\Pinjaman;

use App\Models\LoanInstallment;
use App\Services\LoanInstallmentQuery;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Angsuran (Admin & Pengurus): angsuran lintas pinjaman — jatuh tempo bulan ini, terlambat, belum/sudah
 * dibayar — dengan filter bulan, metode bayar, dan pencarian. Yang terlambat tampil paling atas.
 */
#[Layout('components.layouts.app')]
#[Title('Angsuran Pinjaman')]
class Installments extends Component
{
    use WithPagination;

    public const PER_PAGE = 30;

    #[Url(as: 'tampil', except: 'bulan_ini')]
    public string $filter = 'bulan_ini';

    #[Url(as: 'bulan', except: '')]
    public string $month = '';

    #[Url(as: 'metode', except: '')]
    public string $method = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['filter', 'month', 'method', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, LoanInstallmentQuery::FILTERS) ? $filter : 'bulan_ini';
        if ($this->filter === 'bulan_ini') {
            $this->month = '';
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('filter', 'month', 'method', 'search');
        $this->resetPage();
    }

    #[Computed]
    public function installments()
    {
        return app(LoanInstallmentQuery::class)->query(['filter' => $this->filter, 'month' => $this->month, 'method' => $this->method, 'search' => $this->search])
            ->paginate(self::PER_PAGE)->through(fn (LoanInstallment $i) => LoanInstallmentQuery::toArray($i));
    }

    #[Computed]
    public function summary(): array
    {
        return app(LoanInstallmentQuery::class)->summary();
    }

    public function render()
    {
        return view('livewire.pinjaman.installments');
    }
}
