<?php

namespace App\Livewire\Pinjaman;

use App\Services\LoanArrearsQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Tunggakan (Admin & Pengurus): pinjaman yang punya angsuran lewat jatuh tempo, dikelompokkan menurut umur
 * tunggakan, dengan saringan umur, metode bayar, pencarian, dan urutan (terlama / nominal terbesar).
 */
#[Layout('components.layouts.app')]
#[Title('Tunggakan Pinjaman')]
class Arrears extends Component
{
    use WithPagination;

    public const PER_PAGE = 30;

    #[Url(as: 'umur', except: '')]
    public string $bucket = '';

    #[Url(as: 'metode', except: '')]
    public string $method = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'urut', except: 'terlama')]
    public string $sort = 'terlama';

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
        $this->bucket = array_key_exists($this->bucket, LoanArrearsQuery::BUCKETS) ? $this->bucket : '';
        $this->method = array_key_exists($this->method, \App\Models\Loan::METHODS) ? $this->method : '';
        $this->sort = array_key_exists($this->sort, LoanArrearsQuery::SORTS) ? $this->sort : 'terlama';
        $this->search = mb_substr($this->search, 0, 100);
    }

    public function setBucket(string $bucket): void
    {
        $this->bucket = $this->bucket === $bucket ? '' : $bucket;
        $this->sanitize();
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('bucket', 'method', 'search', 'sort');
        $this->resetPage();
    }

    #[Computed]
    public function all()
    {
        return app(LoanArrearsQuery::class)->all();
    }

    #[Computed]
    public function summary(): array
    {
        return LoanArrearsQuery::summary($this->all);
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $rows = LoanArrearsQuery::filter($this->all, ['bucket' => $this->bucket, 'method' => $this->method, 'search' => $this->search, 'sort' => $this->sort]);
        $page = max(1, min($this->getPage(), (int) ceil(max(1, $rows->count()) / self::PER_PAGE)));

        return new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page);
    }

    public function render()
    {
        return view('livewire.pinjaman.arrears');
    }
}
