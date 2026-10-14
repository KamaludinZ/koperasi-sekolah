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
 * Form pembatalan (void) satu transaksi utuh: alasan wajib (+ penjelasan bila "Lainnya"),
 * pratinjau akibatnya (semua barang kembali ke stok, dana dikembalikan sesuai metode bayar),
 * dan konfirmasi dengan mengetik 4 digit terakhir nomor transaksi. Ditolak bila lewat batas
 * hari atau transaksi sudah punya pengajuan retur/pembatalan yang berjalan.
 * Disimpan lewat TransactionReturnService::requestVoid; pembatalan kecil oleh kasir yang sama langsung
 * disetujui (aturan nilai batas), selainnya menunggu persetujuan Admin/Pengurus.
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

    #[Locked]
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
        if ($this->tx->isVoided()) {
            return 'Transaksi ini sudah dibatalkan.';
        }
        if (TransactionReturnService::expired($this->tx)) {
            return 'Transaksi ini sudah lewat batas '.TransactionReturnService::limitDays().' hari, jadi tidak bisa dibatalkan.';
        }
        $open = $this->tx->pendingReturn()->first();

        return $open ? "Transaksi ini sudah punya pengajuan {$open->number} (Menunggu). Selesaikan pengajuan itu dulu." : null;
    }

    /** Akan disetujui otomatis bila diajukan sekarang oleh pengguna ini? */
    #[Computed]
    public function autoApproval(): ?string
    {
        return TransactionReturnService::autoApproval($this->tx, max(0, (int) $this->tx->total - $this->tx->returned_amount), auth()->user());
    }

    /** Sisa nilai yang dikembalikan (total dikurangi retur yang sudah disetujui). */
    #[Computed]
    public function refundAmount(): int
    {
        return max(0, (int) $this->tx->total - $this->tx->returned_amount);
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
            'reason' => ['required', 'in:'.implode(',', array_keys(TransactionReturn::VOID_REASONS))],
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

        try {
            $r = app(TransactionReturnService::class)->requestVoid($this->tx, $this->reason, trim($this->note) ?: null, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'transaction' ? 'reason' : $field, $messages[0]);
            }

            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => $r->status === 'disetujui'
            ? "Transaksi {$this->tx->invoice_number} dibatalkan ({$r->number}). Kembalikan dana Rp".number_format($r->amount, 0, ',', '.').' ke pembeli.'
            : "Pengajuan pembatalan {$r->number} untuk {$this->tx->invoice_number} dikirim untuk disetujui."]);

        return $this->redirectRoute('transaksi.retur', navigate: true);
    }

    public function render()
    {
        return view('livewire.transaksi.void-form');
    }
}
