<?php

namespace App\Livewire\Transaksi;

use App\Models\Transaction;
use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form pengajuan retur barang dari satu transaksi: pilih jumlah per barang (≤ jumlah dibeli
 * dikurangi yang sudah diretur), alasan, kondisi barang (kembali ke stok atau tidak), cara
 * pengembalian dana, dan catatan. Nilai retur mengikuti harga setelah diskon transaksi.
 * Tahap tampilan: pengajuan disimulasikan (data tiruan), backend retur menyusul.
 */
#[Layout('components.layouts.app')]
#[Title('Retur Barang')]
class ReturnForm extends Component
{
    public const CONDITIONS = ['layak' => 'Masih layak jual — kembali ke stok', 'rusak' => 'Rusak/kedaluwarsa — tidak kembali ke stok'];

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
        return $this->tx->created_at->lt(now()->subDays(Returns::LIMIT_DAYS)->startOfDay());
    }

    /** Rasio harga setelah diskon transaksi (diskon dibagi rata ke semua barang). */
    #[Computed]
    public function ratio(): float
    {
        $subtotal = (float) $this->tx->subtotal;

        return $subtotal > 0 ? max(0, ($subtotal - (float) $this->tx->discount) / $subtotal) : 1.0;
    }

    /** @return list<array{id: int, name: string, price: int, net: int, bought: int, returned: int, left: int}> */
    #[Computed]
    public function lines(): array
    {
        $returned = $this->alreadyReturned();

        return $this->tx->items->sortBy('id')->map(function ($it) use ($returned) {
            $done = $returned[$it->product_name] ?? 0;

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

    /** Jumlah barang yang sudah diretur (disetujui/menunggu) dari pengajuan tiruan transaksi ini. */
    private function alreadyReturned(): array
    {
        $out = [];
        foreach (MockData::transactionReturns() as $r) {
            if ($r['transaction']['id'] === $this->transactionId && $r['status'] !== 'ditolak') {
                foreach ($r['items'] as $it) {
                    $out[$it['name']] = ($out[$it['name']] ?? 0) + $it['qty'];
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> cara pengembalian dana sesuai metode bayar transaksi */
    #[Computed]
    public function refundOptions(): array
    {
        $method = $this->tx->payment_method;
        $keys = match ($method) {
            'tabungan' => ['tabungan', 'tunai'],
            'bon' => ['bon'],
            'qris' => ['qris', 'tunai'],
            default => ['tunai'],
        };
        if ($this->tx->member_id && ! in_array('tabungan', $keys, true) && $method !== 'bon') {
            $keys[] = 'tabungan';
        }

        return array_intersect_key(Returns::REFUND_METHODS, array_flip($keys));
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
            'reason' => ['required', 'in:'.implode(',', array_keys(MockData::RETURN_REASONS))],
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

        // Tahap tampilan: pengajuan belum disimpan; backend retur menyusul.
        $number = 'RTR-'.now()->format('Ymd').'-'.str_pad((string) ($this->transactionId % 10000), 4, '0', STR_PAD_LEFT);
        session()->flash('toast', ['type' => 'success', 'message' => "Pengajuan retur {$number} ({$this->summary['units']} barang, Rp".number_format($this->summary['amount'], 0, ',', '.').') dikirim untuk disetujui. (data contoh)']);

        return $this->redirectRoute('transaksi.retur', navigate: true);
    }

    public function render()
    {
        return view('livewire.transaksi.return-form');
    }
}
