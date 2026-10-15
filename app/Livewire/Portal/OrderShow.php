<?php

namespace App\Livewire\Portal;

use App\Models\Order;
use App\Services\OrderService;
use App\Support\ImpersonationPolicy;
use App\Support\ImpersonationSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Portal anggota → Pesanan → Detail: status & langkah pesanan, riwayat perubahan status, rincian barang
 * & pembayaran (diperbarui otomatis), serta batal selama masih menunggu. Pesanan anggota lain → 404.
 */
#[Layout('components.layouts.portal')]
#[Title('Detail Pesanan')]
class OrderShow extends Component
{
    public const STEPS = ['menunggu', 'diproses', 'siap', 'selesai'];

    #[Locked]
    public int $orderId;

    public bool $cancelling = false;

    public string $reason = '';

    public function mount(int $order): void
    {
        abort_unless(Order::where('member_id', Auth::guard('member')->id())->whereKey($order)->exists(), 404);
        $this->orderId = $order;
    }

    #[Computed]
    public function order(): Order
    {
        return Order::with(['items', 'statusLogs', 'savingType:id,name'])->where('member_id', Auth::guard('member')->id())->findOrFail($this->orderId);
    }

    #[Computed]
    public function deduction(): ?array
    {
        return OrderService::deductionFor($this->order);
    }

    public function cancel(): void
    {
        $this->resetErrorBag();
        if (ImpersonationSession::impersonatingMember()) {
            $this->addError('reason', ImpersonationPolicy::MEMBER_READ_ONLY_MESSAGE);

            return;
        }
        try {
            app(OrderService::class)->cancel($this->order, $this->reason);
        } catch (ValidationException $e) {
            $this->addError('reason', $e->errors()['reason'][0] ?? 'Pesanan tidak bisa dibatalkan.');

            return;
        }
        $this->cancelling = false;
        unset($this->order, $this->deduction);
        $this->dispatch('toast', type: 'success', message: 'Pesanan dibatalkan.'.($this->order->payment_status === 'dikembalikan' ? ' Saldo sudah dikembalikan.' : ''));
    }

    public function render()
    {
        return view('livewire.portal.order-show');
    }
}
