<?php

namespace App\Livewire\Transaksi;

use App\Support\MockData;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Halaman detail satu transaksi, dengan navigasi ke transaksi lebih baru/lama.
 * Tahap frontend: memakai data tiruan (MockData::transactions).
 */
#[Layout('components.layouts.app')]
class Show extends Component
{
    public array $tx;

    public ?int $newerId = null;

    public ?int $olderId = null;

    public function mount(int $transaction): void
    {
        $all = MockData::transactions(); // terbaru dulu
        $index = collect($all)->search(fn ($t) => $t['id'] === $transaction);
        abort_if($index === false, 404);

        $this->tx = $all[$index];
        $this->newerId = $all[$index - 1]['id'] ?? null;
        $this->olderId = $all[$index + 1]['id'] ?? null;
    }

    public function render()
    {
        return view('livewire.transaksi.show')->title('Transaksi '.$this->tx['invoice_number']);
    }
}
