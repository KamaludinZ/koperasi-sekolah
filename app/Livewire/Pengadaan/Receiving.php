<?php

namespace App\Livewire\Pengadaan;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
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
 * diterima), menambah stok barang, memperbarui harga modal, dan membuat tagihan supplier lewat
 * GoodsReceiptService. Disertai riwayat penerimaan terbaru.
 */
#[Layout('components.layouts.app')]
#[Title('Penerimaan Barang')]
class Receiving extends Component
{
    #[Url(as: 'pesanan', except: '')]
    public string $orderId = '';

    /** @var array<int|string, int|string> purchase_order_item_id => jumlah diterima sekarang */
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
        $this->qty = collect($this->lines)->mapWithKeys(fn ($l) => [$l['id'] => $l['qty'] - $l['received_qty']])->all();
    }

    /** Pesanan yang menunggu barang (dipesan atau diterima sebagian), paling lama dulu. */
    #[Computed]
    public function waiting(): array
    {
        return collect(app(PurchaseOrderService::class)->list())->whereIn('status', PurchaseOrder::WAITING)->sortBy('expected_at')->values()->all();
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
        $lines = PurchaseOrderService::lines(PurchaseOrder::with('items')->findOrFail($this->order['id']));
        $stock = Product::whereIn('id', array_filter(array_column($lines, 'product_id')))->pluck('stock', 'id');

        return array_map(fn ($l) => $l + ['stock' => (int) ($stock[$l['product_id']] ?? 0)], $lines);
    }

    #[Computed]
    public function receipts(): array
    {
        return app(GoodsReceiptService::class)->recent(8);
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
            $rules["qty.{$l['id']}"] = ['required', 'integer', 'min:0', 'max:'.($l['qty'] - $l['received_qty'])];
        }
        try {
            $this->validate($rules, [
                'receivedAt.before_or_equal' => 'Tanggal terima tidak boleh setelah hari ini.',
                'receivedAt.after_or_equal' => 'Tanggal terima tidak boleh sebelum tanggal pesan.',
                'qty.*.max' => 'Melebihi sisa pesanan (:max).',
                'qty.*.min' => 'Tidak boleh kurang dari 0.',
                'qty.*.*' => 'Isi angka 0 atau lebih.',
            ]);
            $receipt = app(GoodsReceiptService::class)->receive(PurchaseOrder::findOrFail($this->order['id']), array_map('intval', $this->qty),
                ['received_at' => $this->receivedAt, 'delivery_note' => $this->deliveryNote, 'note' => $this->note], auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: 'Belum tersimpan: periksa isian yang ditandai merah.');
            if (isset($e->errors()['items'])) {
                throw ValidationException::withMessages(['qty' => $e->errors()['items'][0]]);
            }

            throw $e;
        }

        $status = PurchaseOrder::whereKey($this->order['id'])->value('status');
        session()->flash('toast', ['type' => 'success', 'message' => "{$receipt->total_quantity} unit dari {$this->order['number']} diterima ({$receipt->number})"
            .($status === 'diterima' ? ' — pesanan lengkap.' : ' — sisanya masih ditunggu.')]);

        return $this->redirectRoute('pengadaan.penerimaan', navigate: true);
    }

    public function render()
    {
        return view('livewire.pengadaan.receiving');
    }
}
