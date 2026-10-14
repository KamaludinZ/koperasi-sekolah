<?php

namespace App\Livewire\Transaksi;

use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Services\TransactionReturnService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form pengajuan retur barang dari satu transaksi: pilih jumlah per barang (≤ jumlah dibeli
 * dikurangi yang sudah diretur), alasan, kondisi barang (kembali ke stok atau tidak), cara
 * pengembalian dana, dan catatan. Nilai retur mengikuti harga bersih transaksi (setelah diskon, pajak
 * & pembulatan). Disimpan sebagai pengajuan menunggu lewat TransactionReturnService::requestReturn.
 */
#[Layout('components.layouts.app')]
#[Title('Retur Barang')]
class ReturnForm extends Component
{
    public const CONDITIONS = ['layak' => 'Masih layak jual — kembali ke stok', 'rusak' => 'Rusak/kedaluwarsa — tidak kembali ke stok'];

    #[Locked]
    public int $transactionId;

    /** @var array<int, int|string> id item transaksi → jumlah diretur */
    public array $qty = [];

    public string $reason = '';

    public string $condition = 'layak';

    public string $refundMethod = '';

    public string $note = '';

    public function mount(Transaction $transaction): void
    {
        $this->transactionId = $transaction->id;
        $this->refundMethod = array_key_first($this->refundOptions);
        $this->qty = array_fill_keys(array_column($this->lines, 'id'), 0);
    }

    #[Computed]
    public function tx(): Transaction
    {
        return Transaction::with(['items', 'user:id,name', 'member:id,member_code,name,type,class'])->findOrFail($this->transactionId);
    }

    #[Computed]
    public function expired(): bool
    {
        return TransactionReturnService::expired($this->tx) || $this->tx->isVoided();
    }

    /** Rasio harga bersih transaksi (total ÷ subtotal: diskon, pajak & pembulatan dibagi rata). */
    #[Computed]
    public function ratio(): float
    {
        return TransactionReturnService::ratio($this->tx);
    }

    /** @return list<array{id: int, name: string, price: int, net: int, bought: int, returned: int, left: int}> */
    #[Computed]
    public function lines(): array
    {
        $returned = $this->tx->returnedQuantities();

        return $this->tx->items->sortBy('id')->map(function ($it) use ($returned) {
            $done = $returned[$it->id] ?? 0;

            return [
                'id' => $it->id,
                'name' => $it->product_name,
                'price' => (int) $it->price,
                'net' => (int) round((float) $it->price * $this->ratio),
                'bought' => $it->quantity,
                'returned' => $done,
                'left' => max(0, $it->quantity - $done),
            ];
        })->values()->all();
    }

    /** @return array<string, string> cara pengembalian dana sesuai metode bayar transaksi */
    #[Computed]
    public function refundOptions(): array
    {
        return TransactionReturnService::refundOptions($this->tx);
    }

    #[Computed]
    public function summary(): array
    {
        $units = 0;
        $amount = 0;
        foreach ($this->lines as $l) {
            $q = max(0, min($l['left'], (int) ($this->qty[$l['id']] ?? 0)));
            $units += $q;
            $amount += $q * $l['net'];
        }

        return ['units' => $units, 'amount' => $amount];
    }

    public function returnAll(): void
    {
        foreach ($this->lines as $l) {
            $this->qty[$l['id']] = $l['left'];
        }
        unset($this->summary);
    }

    public function submit()
    {
        abort_if($this->expired, 403);

        $rules = [
            'reason' => ['required', 'in:'.implode(',', array_keys(TransactionReturn::RETURN_REASONS))],
            'condition' => ['required', 'in:'.implode(',', array_keys(self::CONDITIONS))],
            'refundMethod' => ['required', 'in:'.implode(',', array_keys($this->refundOptions))],
            'note' => [$this->reason === 'lainnya' ? 'required' : 'nullable', 'string', 'max:200'],
        ];
        $messages = [
            'reason.required' => 'Pilih alasan retur.',
            'refundMethod.in' => 'Cara pengembalian ini tidak tersedia untuk transaksi ini.',
            'note.required' => 'Jelaskan alasan retur.',
            'note.max' => 'Catatan maksimal 200 karakter.',
        ];
        foreach ($this->lines as $l) {
            $rules['qty.'.$l['id']] = ['nullable', 'integer', 'min:0', 'max:'.$l['left']];
            $messages['qty.'.$l['id'].'.max'] = "Maksimal {$l['left']}.";
            $messages['qty.'.$l['id'].'.min'] = 'Tidak boleh minus.';
        }
        $this->validate($rules, $messages);

        unset($this->summary);
        if ($this->summary['units'] === 0) {
            $this->addError('qty', 'Isi jumlah barang yang diretur.');

            return null;
        }

        try {
            $r = app(TransactionReturnService::class)->requestReturn($this->tx, array_map('intval', array_filter($this->qty, fn ($q) => (int) $q > 0)),
                $this->reason, $this->condition === 'layak', $this->refundMethod, trim($this->note) ?: null, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(match ($field) {
                    'refund_method' => 'refundMethod',
                    'transaction' => 'qty',
                    default => $field,
                }, $messages[0]);
            }
            unset($this->lines, $this->summary);

            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => "Pengajuan retur {$r->number} ({$r->quantity} barang, Rp".number_format($r->amount, 0, ',', '.').') dikirim untuk disetujui.']);

        return $this->redirectRoute('transaksi.retur', navigate: true);
    }

    public function render()
    {
        return view('livewire.transaksi.return-form');
    }
}
