<?php

namespace App\Livewire\Transaksi;

use App\Models\Transaction;
use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form pembatalan (void) satu transaksi utuh: alasan wajib (+ penjelasan bila "Lainnya"),
 * pratinjau akibatnya (semua barang kembali ke stok, dana dikembalikan sesuai metode bayar),
 * dan konfirmasi dengan mengetik 4 digit terakhir nomor transaksi. Ditolak bila lewat batas
 * hari atau transaksi sudah punya pengajuan retur/pembatalan yang berjalan.
 * Tahap tampilan: pengajuan disimulasikan (data tiruan), backend pembatalan menyusul.
 */
#[Layout('components.layouts.app')]
#[Title('Batalkan Transaksi')]
class VoidForm extends Component
{
    public const EFFECTS = [
        'tunai' => 'Uang tunai dikembalikan ke pembeli dari laci kas.',
        'qris' => 'Dana dikembalikan lewat transfer ke pembeli.',
        'tabungan' => 'Saldo tabungan anggota dikembalikan.',
        'bon' => 'Catatan bon anggota dihapus.',
    ];

    public int $transactionId;

    public string $reason = '';

    public string $note = '';

    public string $confirmCode = '';

    public function mount(Transaction $transaction): void
    {
        $this->transactionId = $transaction->id;
    }

    #[Computed]
    public function tx(): Transaction
    {
        return Transaction::with(['items', 'user:id,name', 'member:id,member_code,name,type,class'])->findOrFail($this->transactionId);
    }

    /** Alasan tidak bisa dibatalkan, atau null bila boleh. */
    #[Computed]
    public function blocked(): ?string
    {
        if ($this->tx->created_at->lt(now()->subDays(Returns::LIMIT_DAYS)->startOfDay())) {
            return 'Transaksi ini sudah lewat batas '.Returns::LIMIT_DAYS.' hari, jadi tidak bisa dibatalkan.';
        }
        $open = collect(MockData::transactionReturns())->first(fn ($r) => $r['transaction']['id'] === $this->transactionId && $r['status'] !== 'ditolak');

        return $open ? "Transaksi ini sudah punya pengajuan {$open['number']} (".Returns::STATUSES[$open['status']].'). Selesaikan pengajuan itu dulu.' : null;
    }

    /** 4 digit terakhir nomor transaksi untuk konfirmasi. */
    #[Computed]
    public function code(): string
    {
        return substr(preg_replace('/\D/', '', $this->tx->invoice_number), -4);
    }

    public function submit()
    {
        abort_if($this->blocked !== null, 403);

        $this->validate([
            'reason' => ['required', 'in:'.implode(',', array_keys(MockData::VOID_REASONS))],
            'note' => [$this->reason === 'lainnya' ? 'required' : 'nullable', 'string', 'min:'.($this->reason === 'lainnya' ? 5 : 0), 'max:200'],
            'confirmCode' => ['required', 'in:'.$this->code],
        ], [
            'reason.required' => 'Pilih alasan pembatalan.',
            'note.required' => 'Jelaskan alasan pembatalan.',
            'note.min' => 'Penjelasan minimal 5 karakter.',
            'note.max' => 'Catatan maksimal 200 karakter.',
            'confirmCode.required' => 'Ketik 4 digit terakhir nomor transaksi.',
            'confirmCode.in' => 'Kode konfirmasi tidak cocok.',
        ]);

        // Tahap tampilan: pengajuan belum disimpan; backend pembatalan menyusul.
        $number = 'BTL-'.now()->format('Ymd').'-'.str_pad((string) ($this->transactionId % 10000), 4, '0', STR_PAD_LEFT);
        session()->flash('toast', ['type' => 'success', 'message' => "Pengajuan pembatalan {$number} untuk {$this->tx->invoice_number} dikirim untuk disetujui. (data contoh)"]);

        return $this->redirectRoute('transaksi.retur', navigate: true);
    }

    public function render()
    {
        return view('livewire.transaksi.void-form');
    }
}
