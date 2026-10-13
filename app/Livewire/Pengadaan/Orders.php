<?php

namespace App\Livewire\Pengadaan;

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengadaan → Pesanan pembelian (Admin & Pengurus): semua pesanan ke supplier dengan status
 * (draf, dipesan, diterima sebagian, diterima, dibatalkan), tanggal pesan & perkiraan tiba
 * (tanda terlambat), kemajuan penerimaan, dan nilai; saring status, supplier, pencarian nomor.
 * Data dari PurchaseOrderService; panel rincian bisa menandai draf dipesan atau membatalkan pesanan.
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
        return app(PurchaseOrderService::class)->list();
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
        return collect(app(SupplierService::class)->list())->pluck('name', 'id')->all();
    }

    #[Computed]
    public function selected(): ?array
    {
        $o = $this->selectedId ? PurchaseOrder::with(['items', 'supplier', 'creator'])->find($this->selectedId) : null;

        return $o ? PurchaseOrderService::toArray($o) + ['lines' => PurchaseOrderService::lines($o)] : null;
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
        $this->reset('selectedId', 'cancelling', 'cancelReason');
        $this->resetValidation();
    }

    public bool $cancelling = false;

    public string $cancelReason = '';

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('kelola-pengadaan');
    }

    public function send(): void
    {
        $order = $this->canManage && $this->selectedId ? PurchaseOrder::find($this->selectedId) : null;
        if (! $order) {
            return;
        }
        try {
            app(PurchaseOrderService::class)->send($order);
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: collect($e->errors())->flatten()->first());

            return;
        }
        unset($this->all, $this->orders, $this->tabs, $this->selected);
        $this->dispatch('toast', type: 'success', message: "Pesanan {$order->number} ditandai dipesan.");
    }

    public function cancel(): void
    {
        $order = $this->canManage && $this->selectedId ? PurchaseOrder::find($this->selectedId) : null;
        if (! $order) {
            return;
        }
        $this->validate(['cancelReason' => ['required', 'string', 'min:3', 'max:200']], ['cancelReason.required' => 'Tulis alasan pembatalan.', 'cancelReason.min' => 'Alasan minimal 3 huruf.']);
        try {
            app(PurchaseOrderService::class)->cancel($order, $this->cancelReason);
        } catch (ValidationException $e) {
            $this->addError('cancelReason', collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('cancelling', 'cancelReason');
        unset($this->all, $this->orders, $this->tabs, $this->selected);
        $this->dispatch('toast', type: 'success', message: "Pesanan {$order->number} dibatalkan.");
    }

    public function render()
    {
        return view('livewire.pengadaan.orders');
    }
}
