<?php

namespace App\Livewire\Pengadaan;

use App\Models\Product;
use App\Support\MockData;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengadaan → Penerimaan barang (Admin & Pengurus): pilih pesanan yang sedang ditunggu, isi jumlah
 * yang datang per barang (bawaan = sisa yang belum diterima), nomor surat jalan, tanggal, dan
 * catatan; pratinjau stok sebelum → sesudah. Menyimpan memperbarui status pesanan (sebagian /
 * diterima). Disertai riwayat penerimaan terbaru. Tahap ini memakai MockData; penambahan stok
 * sungguhan menyusul di backend.
 */
#[Layout('components.layouts.app')]
#[Title('Penerimaan Barang')]
class Receiving extends Component
{
    #[Url(as: 'pesanan', except: '')]
    public string $orderId = '';

    /** @var array<int|string, int|string> product_id => jumlah diterima sekarang */
    public array $qty = [];

    public string $receivedAt = '';

    public string $deliveryNote = '';

    public string $note = '';

    public function mount(): void
    {
        $this->receivedAt = now()->toDateString();
        $this->prefill();
    }

    public function updatedOrderId(): void
    {
        $this->resetValidation();
        $this->prefill();
    }

    private function prefill(): void
    {
        $this->qty = collect($this->lines)->mapWithKeys(fn ($l) => [$l['product_id'] => $l['qty'] - $l['received_qty']])->all();
    }

    /** Pesanan yang menunggu barang (dipesan atau diterima sebagian), paling lama dulu. */
    #[Computed]
    public function waiting(): array
    {
        return collect(MockData::purchaseOrders())->whereIn('status', ['dipesan', 'sebagian'])->sortBy('expected_at')->values()->all();
    }

    #[Computed]
    public function order(): ?array
    {
        return collect($this->waiting)->firstWhere('id', (int) $this->orderId);
    }

    #[Computed]
    public function lines(): array
    {
        if (! $this->order) {
            return [];
        }
        $stock = Product::whereIn('id', array_column(MockData::purchaseOrderItems($this->order['id']), 'product_id'))->pluck('stock', 'id');

        return array_map(fn ($l) => $l + ['stock' => (int) ($stock[$l['product_id']] ?? 0)], MockData::purchaseOrderItems($this->order['id']));
    }

    #[Computed]
    public function receipts(): array
    {
        return array_slice(MockData::receipts(), 0, 8);
    }

    public function receiveAll(): void
    {
        $this->prefill();
    }

    public function save()
    {
        if (! $this->order) {
            $this->addError('orderId', 'Pilih pesanan yang barangnya datang.');

            return null;
        }
        $rules = ['receivedAt' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.$this->order['ordered_at']],
            'deliveryNote' => ['nullable', 'string', 'max:50'], 'note' => ['nullable', 'string', 'max:200']];
        foreach ($this->lines as $l) {
            $rules["qty.{$l['product_id']}"] = ['required', 'integer', 'min:0', 'max:'.($l['qty'] - $l['received_qty'])];
        }
        try {
            $this->validate($rules, [
                'receivedAt.before_or_equal' => 'Tanggal terima tidak boleh setelah hari ini.',
                'receivedAt.after_or_equal' => 'Tanggal terima tidak boleh sebelum tanggal pesan.',
                'qty.*.max' => 'Melebihi sisa pesanan (:max).',
                'qty.*.min' => 'Tidak boleh kurang dari 0.',
                'qty.*.*' => 'Isi angka 0 atau lebih.',
            ]);
            if (array_sum(array_map('intval', $this->qty)) === 0) {
                throw ValidationException::withMessages(['qty' => 'Isi jumlah barang yang datang (minimal satu barang).']);
            }
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: 'Belum tersimpan: periksa isian yang ditandai merah.');

            throw $e;
        }

        $receipt = MockData::receiveOrder($this->order['id'], array_map('intval', $this->qty),
            ['received_at' => $this->receivedAt, 'delivery_note' => trim($this->deliveryNote) ?: null, 'note' => trim($this->note) ?: null],
            auth()->user()->name);
        $status = collect(MockData::purchaseOrders())->firstWhere('id', $this->order['id'])['status'];
        session()->flash('toast', ['type' => 'success', 'message' => "{$receipt['qty']} unit dari {$receipt['number']} diterima"
            .($status === 'diterima' ? ' — pesanan lengkap.' : ' — sisanya masih ditunggu.')]);

        return $this->redirectRoute('pengadaan.penerimaan', navigate: true);
    }

    public function render()
    {
        return view('livewire.pengadaan.receiving');
    }
}
