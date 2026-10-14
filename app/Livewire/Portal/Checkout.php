<?php

namespace App\Livewire\Portal;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\PortalPinService;
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
 * (potong saldo tabungan/simpanan, tunai di kasir, QRIS/transfer), lalu verifikasi PIN transaksi dan kirim pesanan (OrderService).
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

    /** Langkah konfirmasi: ringkasan pesanan + PIN transaksi. */
    public bool $confirming = false;

    public string $pin = '';

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

    /** Rincian harga sesuai cara bayar terpilih (pajak & pembulatan seperti di kasir). */
    #[Computed]
    public function price(): array
    {
        return app(OrderService::class)->quote($this->cart['total'], $this->payment);
    }

    /**
     * Rincian potong saldo untuk pilihan bayar saat ini (null bila bukan potong saldo):
     * sumber, saldo sebelum/sesudah, kekurangan.
     */
    #[Computed]
    public function deduction(): ?array
    {
        if (! in_array($this->payment, ['tabungan', 'simpanan'], true)) {
            return null;
        }
        $b = $this->balances;
        [$source, $before] = $this->payment === 'tabungan'
            ? ['Tabungan', $b['tabungan']]
            : (($t = collect($b['simpanan'])->firstWhere('id', $this->savingTypeId)) ? [$t['name'], $t['balance']] : [null, 0]);
        if ($source === null) {
            return null;
        }
        $total = $this->balanceTotal;

        return ['source' => $source, 'before' => $before, 'amount' => $total, 'after' => $before - $total, 'short' => max(0, $total - $before)];
    }

    /** Total yang harus dibayar bila memakai saldo (non-tunai). */
    #[Computed]
    public function balanceTotal(): int
    {
        return app(OrderService::class)->quote($this->cart['total'], 'tabungan')['payable'];
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
            $this->savingTypeId = collect($this->balances['simpanan'])->firstWhere(fn ($t) => $t['balance'] >= $this->balanceTotal)['id'] ?? null;
        }
        $this->resetValidation(['payment', 'savingTypeId']);
    }

    #[Computed]
    public function pinAttempts(): array
    {
        return app(PortalPinService::class)->attempts(Auth::guard('member')->user());
    }

    /** Buka langkah verifikasi PIN (pilihan dicek dulu tanpa membuat pesanan). */
    public function review(): void
    {
        $this->resetErrorBag();
        if ($this->fulfillment === 'antar' && mb_strlen(trim($this->deliveryTo)) < 2) {
            $this->addError('deliveryTo', 'Tulis tujuan antar (mis. Kelas 7A atau Ruang Guru).');

            return;
        }
        if ($this->payment === 'simpanan' && ! $this->savingTypeId) {
            $this->addError('savingTypeId', 'Pilih simpanan yang dipotong.');

            return;
        }
        $this->pin = '';
        $this->confirming = true;
    }

    /** Kirim pesanan setelah PIN transaksi benar. */
    public function place()
    {
        $this->resetErrorBag();
        $member = Auth::guard('member')->user();
        try {
            app(PortalPinService::class)->verify($member, $this->pin);
        } catch (ValidationException $e) {
            $this->pin = '';
            $this->confirming = true;
            unset($this->pinAttempts);
            $this->addError('pin', $e->errors()['pin'][0]);

            return null;
        }
        $this->pin = '';
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
            $this->confirming = false;
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
