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
 * Pinjaman → Konsol (Admin & Pengurus): seluruh pinjaman anggota dalam satu tabel — kode, anggota, pokok,
 * bunga, tenor, metode bayar, status, dan sisa. Yang perlu tindakan (menunggak, diajukan) tampil paling atas.
 */
#[Layout('components.layouts.app')]
#[Title('Konsol Pinjaman')]
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'metode', except: '')]
    public string $method = '';

    /** Filter berubah → kembali ke halaman 1; nilai di luar daftar diabaikan. */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'method'], true)) {
            $this->resetPage();
        }
    }

    /** Kartu ringkasan: klik status untuk menyaring (klik lagi untuk melepas). */
    public function filterStatus(string $status): void
    {
        $this->status = $this->status === $status || ! array_key_exists($status, Loan::STATUSES) ? '' : $status;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'method');
        $this->resetPage();
    }

    private function filters(): array
    {
        return [
            'search' => mb_substr($this->search, 0, 100),
            'status' => array_key_exists($this->status, Loan::STATUSES) ? $this->status : '',
            'method' => array_key_exists($this->method, Loan::METHODS) ? $this->method : '',
        ];
    }

    #[Computed]
    public function loans()
    {
        return app(LoanService::class)->query($this->filters())->paginate(self::PER_PAGE)->through(fn (Loan $l) => LoanService::toArray($l));
    }

    /** Tanda menunggak efektif untuk pinjaman di halaman ini (id → jumlah, nominal, lama terlambat). */
    #[Computed]
    public function overdue(): array
    {
        return LoanService::overdueFlags(collect($this->loans->items())->pluck('id')->all());
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
