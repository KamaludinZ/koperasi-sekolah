<?php

namespace App\Livewire\Transaksi;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Transaksi → Pesanan anggota: pesanan checkout mandiri dari portal. Petugas memajukan status
 * (menunggu → diproses → siap → selesai), mencatat bayar tunai/QRIS, atau membatalkan (stok & saldo kembali).
 */
#[Layout('components.layouts.app')]
#[Title('Pesanan Anggota')]
class MemberOrders extends Component
{
    use NotifiesSave, WithPagination;

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'lihat', except: null)]
    public ?int $viewing = null;

    public string $note = '';

    public bool $cancelling = false;

    public string $reason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->status = array_key_exists($this->status, Order::STATUSES) ? $this->status : '';
            $this->resetPage();
        }
    }

    #[Computed]
    public function orders()
    {
        return OrderService::staffQuery($this->status, $this->search)->paginate(25);
    }

    #[Computed]
    public function counts(): array
    {
        return OrderService::statusCounts();
    }

    #[Computed]
    public function detail(): ?array
    {
        $o = $this->viewing ? Order::find($this->viewing) : null;

        return $o ? OrderService::detail($o) : null;
    }

    public function show(int $id): void
    {
        $this->viewing = $id;
        $this->reset('note', 'cancelling', 'reason');
        $this->resetValidation();
        unset($this->detail);
    }

    public function close(): void
    {
        $this->viewing = null;
    }

    private function run(callable $fn, string $ok): void
    {
        $this->resetErrorBag();
        try {
            $fn(app(OrderService::class), Order::findOrFail($this->viewing));
        } catch (ValidationException $e) {
            $this->addError('action', collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('note', 'cancelling', 'reason');
        unset($this->detail, $this->orders, $this->counts);
        $this->toast('success', $ok);
    }

    public function advance(string $to): void
    {
        $this->run(fn ($svc, $o) => $svc->transition($o, $to, auth()->user(), $this->note), 'Status pesanan: '.mb_strtolower(Order::STATUSES[$to] ?? $to).'.');
    }

    public function markPaid(string $method): void
    {
        $this->run(fn ($svc, $o) => $svc->markPaid($o, $method, auth()->user()), 'Pembayaran '.($method === 'tunai' ? 'tunai' : 'QRIS').' dicatat.');
    }

    public function cancel(): void
    {
        $this->run(fn ($svc, $o) => $svc->cancel($o, $this->reason, auth()->id(), auth()->user()->name), 'Pesanan dibatalkan; stok & saldo dikembalikan.');
    }

    public function render()
    {
        return view('livewire.transaksi.member-orders');
    }
}
