<?php

namespace App\Livewire\Pengadaan;

use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengadaan → Pesanan pembelian (Admin & Pengurus): semua pesanan ke supplier dengan status
 * (draf, dipesan, diterima sebagian, diterima, dibatalkan), tanggal pesan & perkiraan tiba
 * (tanda terlambat), kemajuan penerimaan, dan nilai; saring status, supplier, pencarian nomor.
 * Tahap ini memakai MockData::purchaseOrders(); backend menyusul dengan kontrak yang sama.
 */
#[Layout('components.layouts.app')]
#[Title('Pesanan Pembelian')]
class Orders extends Component
{
    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'supplier', except: '')]
    public string $supplier = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $selectedId = null;

    #[Computed]
    public function all(): array
    {
        return collect(MockData::purchaseOrders())->sortByDesc('ordered_at')->values()->all();
    }

    #[Computed]
    public function orders(): array
    {
        $term = mb_strtolower(trim($this->search));

        return collect($this->all)
            ->when($this->status === 'berjalan', fn ($c) => $c->whereIn('status', ['draf', 'dipesan', 'sebagian']))
            ->when($this->status === 'terlambat', fn ($c) => $c->filter(fn ($o) => self::isLate($o)))
            ->when(isset(Index::ORDER_STATUSES[$this->status]), fn ($c) => $c->where('status', $this->status))
            ->when($this->supplier !== '', fn ($c) => $c->where('supplier_id', (int) $this->supplier))
            ->when($term !== '', fn ($c) => $c->filter(fn ($o) => str_contains(mb_strtolower($o['number'].' '.$o['supplier']), $term)))
            ->values()->all();
    }

    /** Status di tab: gabungan + status tunggal, beserta jumlahnya. */
    #[Computed]
    public function tabs(): array
    {
        $all = collect($this->all);

        return ['' => ['Semua', $all->count()], 'berjalan' => ['Berjalan', $all->whereIn('status', ['draf', 'dipesan', 'sebagian'])->count()],
            'terlambat' => ['Terlambat', $all->filter(fn ($o) => self::isLate($o))->count()]]
            + collect(Index::ORDER_STATUSES)->map(fn ($s, $k) => [$s[0], $all->where('status', $k)->count()])->all();
    }

    #[Computed]
    public function supplierOptions(): array
    {
        return collect(MockData::suppliers())->pluck('name', 'id')->all();
    }

    #[Computed]
    public function selected(): ?array
    {
        $o = $this->selectedId ? collect($this->all)->firstWhere('id', $this->selectedId) : null;

        return $o ? $o + ['lines' => MockData::purchaseOrderItems($o['id'])] : null;
    }

    public static function isLate(array $o): bool
    {
        return in_array($o['status'], ['dipesan', 'sebagian'], true) && $o['expected_at'] < now()->toDateString();
    }

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, $this->tabs) ? $status : '';
    }

    public function show(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    public function render()
    {
        return view('livewire.pengadaan.orders');
    }
}
