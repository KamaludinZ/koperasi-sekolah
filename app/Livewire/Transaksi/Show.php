<?php

namespace App\Livewire\Transaksi;

use App\Models\Transaction;
use App\Services\TransactionHistory;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Halaman detail satu transaksi, dengan navigasi ke transaksi lebih baru/lama.
 * Data dari TransactionHistory::detail (sama dengan GET /transaksi/{id}/rincian).
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    public array $tx;

    public ?int $newerId = null;

    public ?int $olderId = null;

    public function mount(Transaction $transaction): void
    {
        $this->tx = app(TransactionHistory::class)->detail($transaction);
        $this->newerId = $this->tx['newer_id'];
        $this->olderId = $this->tx['older_id'];
    }

    public function render()
    {
        return view('livewire.transaksi.show')->title('Transaksi '.$this->tx['invoice_number']);
    }
}
