<?php

namespace App\Livewire\Pinjaman;

use App\Models\Loan;
use App\Services\LoanService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pinjaman → Konsol (Admin & Pengurus): seluruh pinjaman anggota dalam satu tabel — kode, anggota, pokok,
 * bunga, tenor, metode bayar, status, dan sisa. Yang perlu tindakan (menunggak, diajukan) tampil paling atas.
 */
#[Layout('components.layouts.app')]
#[Title('Konsol Pinjaman')]
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Computed]
    public function loans()
    {
        return app(LoanService::class)->query()->paginate(self::PER_PAGE)->through(fn (Loan $l) => LoanService::toArray($l));
    }

    #[Computed]
    public function stats(): array
    {
        return app(LoanService::class)->stats();
    }

    public function render()
    {
        return view('livewire.pinjaman.index');
    }
}
