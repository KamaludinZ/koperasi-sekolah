<?php

namespace App\Livewire\Portal;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\QrisImageService;
use App\Services\SettingsService;
use App\Support\PortalCart;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Portal anggota → Checkout: ringkasan keranjang, pilih pengambilan (ambil/antar) & pembayaran
 * (potong saldo tabungan/simpanan, tunai di kasir, QRIS/transfer), lalu kirim pesanan (OrderService).
 */
#[Layout('components.layouts.portal')]
#[Title('Checkout')]
class Checkout extends Component
{
    public string $fulfillment = 'ambil';

    public string $deliveryTo = '';

    public string $payment = 'tunai';

    public ?int $savingTypeId = null;

    public string $note = '';

    #[Locked]
    public string $ref = '';

    public function mount()
    {
        if (! PortalCart::summary()['count']) {
            return $this->redirectRoute('portal.belanja', navigate: true);
        }
        $this->ref = (string) Str::uuid();
        $this->deliveryTo = (string) (Auth::guard('member')->user()->class ? 'Kelas '.Auth::guard('member')->user()->class : '');

        return null;
    }

    #[Computed]
    public function cart(): array
    {
        return PortalCart::summary();
    }

    #[Computed]
    public function balances(): array
    {
        return app(OrderService::class)->balanceOptions(Auth::guard('member')->user());
    }

    #[Computed]
    public function qrisUrl(): ?string
    {
        return QrisImageService::url(app(SettingsService::class)->section('profile')['qris'] ?? null);
    }

    public function updatedPayment(): void
    {
        if ($this->payment === 'simpanan' && ! $this->savingTypeId) {
            $this->savingTypeId = collect($this->balances['simpanan'])->firstWhere(fn ($t) => $t['balance'] >= $this->cart['total'])['id'] ?? null;
        }
        $this->resetValidation(['payment', 'savingTypeId']);
    }

    public function place()
    {
        $this->resetErrorBag();
        try {
            $order = app(OrderService::class)->place(Auth::guard('member')->user(), PortalCart::items(), [
                'fulfillment' => $this->fulfillment, 'delivery_to' => $this->deliveryTo, 'payment_method' => $this->payment,
                'saving_type_id' => $this->savingTypeId, 'note' => $this->note,
            ], $this->ref);
        } catch (ValidationException $e) {
            $map = ['payment_method' => 'payment', 'saving_type_id' => 'savingTypeId', 'delivery_to' => 'deliveryTo'];
            foreach ($e->errors() as $key => $messages) {
                $this->addError($map[$key] ?? $key, $messages[0]);
            }
            unset($this->cart, $this->balances);

            return null;
        }

        PortalCart::clear();
        session()->flash('toast', ['type' => 'success', 'message' => "Pesanan {$order->code} terkirim. ".($order->payment_status === 'lunas' ? 'Sudah dibayar dari saldo.' : 'Bayar saat '.($order->fulfillment === 'antar' ? 'barang diantar.' : 'mengambil di koperasi.'))]);

        return \Illuminate\Support\Facades\Route::has('portal.pesanan.show')
            ? $this->redirectRoute('portal.pesanan.show', ['order' => $order->id], navigate: true)
            : $this->redirectRoute('portal', navigate: true);
    }

    public function render()
    {
        return view('livewire.portal.checkout', ['payments' => Order::PAYMENTS, 'fulfillments' => Order::FULFILLMENTS]);
    }
}
