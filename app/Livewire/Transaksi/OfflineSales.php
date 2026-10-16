<?php

namespace App\Livewire\Transaksi;

use App\Models\OfflineSale;
use App\Models\User;
use App\Services\OfflineSaleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Transaksi → Penjualan offline: kotak masuk penjualan dari kasir offline yang sudah disinkronkan — status
 * (diterima / perlu dicek / ditolak), tanda masalah (stok kurang, tanpa shift, barang tiada, terlambat), alasan
 * penolakan, nomor resmi & tautan transaksi. Admin/Pengurus menandai kiriman yang bermasalah sudah ditinjau dengan
 * catatan (tercatat di audit). Kasir hanya melihat kirimannya sendiri.
 */
#[Layout('components.layouts.app')]
#[Title('Penjualan Offline')]
class OfflineSales extends Component
{
    use WithPagination;

    public const FILTERS = ['bermasalah' => 'Perlu ditinjau', 'perlu_dicek' => 'Perlu dicek', 'ditolak' => 'Ditolak', 'diterima' => 'Diterima', 'ditinjau' => 'Sudah ditinjau', 'semua' => 'Semua'];

    #[Url(as: 'status', except: 'bermasalah')]
    public string $filter = 'bermasalah';

    #[Url(as: 'kasir', except: '')]
    public string $cashier = '';

    public ?int $reviewing = null;

    public string $note = '';

    public function updated(string $property): void
    {
        $this->filter = isset(self::FILTERS[$this->filter]) ? $this->filter : 'bermasalah';
        $this->cashier = ctype_digit($this->cashier) ? $this->cashier : '';
        if (in_array($property, ['filter', 'cashier'], true)) {
            $this->resetPage();
        }
    }

    /** Admin/Pengurus meninjau semua kiriman; kasir hanya miliknya. */
    #[Computed]
    public function canReview(): bool
    {
        return Gate::allows('tinjau-penjualan-offline');
    }

    private function base()
    {
        return OfflineSale::query()->when(! $this->canReview, fn ($q) => $q->where('user_id', Auth::guard('web')->id()));
    }

    #[Computed]
    public function counts(): array
    {
        $rows = $this->base()->selectRaw('status, reviewed_at IS NULL as open, count(*) as n')->groupBy('status', 'open')->get();
        $c = fn (string $status, ?bool $open = null) => (int) $rows->filter(fn ($r) => $r->status === $status && ($open === null || (bool) $r->open === $open))->sum('n');

        return [
            'bermasalah' => $c('perlu_dicek', true) + $c('ditolak', true), 'perlu_dicek' => $c('perlu_dicek'), 'ditolak' => $c('ditolak'),
            'diterima' => $c('diterima'), 'ditinjau' => (int) $this->base()->whereNotNull('reviewed_at')->count(), 'semua' => (int) $rows->sum('n'),
        ];
    }

    #[Computed]
    public function sales()
    {
        return $this->base()->with(['user:id,name', 'transaction:id,invoice_number', 'reviewer:id,name'])
            ->when($this->filter === 'bermasalah', fn ($q) => $q->whereIn('status', ['perlu_dicek', 'ditolak'])->whereNull('reviewed_at'))
            ->when(in_array($this->filter, ['perlu_dicek', 'ditolak', 'diterima'], true), fn ($q) => $q->where('status', $this->filter))
            ->when($this->filter === 'ditinjau', fn ($q) => $q->whereNotNull('reviewed_at'))
            ->when($this->cashier !== '' && $this->canReview, fn ($q) => $q->where('user_id', (int) $this->cashier))
            ->latest('received_at')->latest('id')->paginate(25);
    }

    #[Computed]
    public function cashiers(): array
    {
        return $this->canReview ? User::whereIn('id', OfflineSale::select('user_id')->distinct())->orderBy('name')->pluck('name', 'id')->all() : [];
    }

    public function startReview(int $id): void
    {
        Gate::authorize('tinjau-penjualan-offline');
        $this->reviewing = $id;
        $this->note = '';
        $this->resetValidation();
    }

    public function review(OfflineSaleService $service): void
    {
        Gate::authorize('tinjau-penjualan-offline');
        $this->validate(['note' => ['required', 'string', 'min:5', 'max:300']], ['note.required' => 'Tulis apa yang sudah dicek/dilakukan.', 'note.min' => 'Catatan minimal 5 karakter.']);
        $sale = OfflineSale::whereKey($this->reviewing)->whereIn('status', ['perlu_dicek', 'ditolak'])->whereNull('reviewed_at')->first();
        if ($sale) {
            $service->review($sale, Auth::guard('web')->user(), $this->note);
            $this->dispatch('toast', type: 'success', message: "Kiriman {$sale->local_number} ditandai sudah ditinjau.");
        }
        $this->reviewing = null;
        $this->note = '';
        unset($this->sales, $this->counts);
    }

    public function render()
    {
        return view('livewire.transaksi.offline-sales');
    }
}
