<?php

namespace App\Livewire\Pengadaan;

use App\Services\SupplierBillService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengadaan → Hutang → Bayar (Admin & Pengurus): bayar satu atau beberapa tagihan ke satu supplier
 * sekaligus. Jumlah dibayar dialokasikan otomatis ke tagihan terpilih mulai jatuh tempo terdekat
 * (bisa diubah per tagihan), lengkap dengan tanggal, cara bayar, no. bukti, dan catatan; ringkasan
 * menunjukkan tagihan yang lunas & sisa. Disimpan lewat SupplierBillService::pay().
 */
#[Layout('components.layouts.app')]
#[Title('Bayar Hutang')]
class PaymentForm extends Component
{
    #[Url(as: 'supplier', except: '')]
    public string $supplierId = '';

    /** @var list<int> tagihan yang dibayar */
    public array $selected = [];

    /** @var array<int|string, int|string> payable_id => jumlah */
    public array $alloc = [];

    public int|string|null $total = null;

    public string $paidAt = '';

    public string $method = 'transfer';

    public string $reference = '';

    public string $note = '';

    public function mount(): void
    {
        $this->paidAt = now()->toDateString();
        $this->selectAll();
    }

    public function updatedSupplierId(): void
    {
        $this->resetValidation();
        $this->selectAll();
    }

    #[Computed]
    public function suppliers(): array
    {
        return collect(app(SupplierBillService::class)->list())->where('status', '!=', 'lunas')
            ->groupBy('supplier_id')->map(fn ($g) => ['id' => $g->first()['supplier_id'], 'name' => $g->first()['supplier'],
                'remaining' => $g->sum(fn ($p) => $p['amount'] - $p['paid']), 'count' => $g->count()])
            ->sortBy('name')->values()->all();
    }

    #[Computed]
    public function bills(): array
    {
        return collect(app(SupplierBillService::class)->list())->where('supplier_id', (int) $this->supplierId)->where('status', '!=', 'lunas')
            ->sortBy('due_at')->map(fn ($p) => $p + ['remaining' => $p['amount'] - $p['paid']])->values()->all();
    }

    private function selectAll(): void
    {
        $this->selected = array_column($this->bills, 'id');
        $this->total = array_sum(array_column($this->bills, 'remaining')) ?: null;
        $this->allocate();
    }

    /** Bagi jumlah dibayar ke tagihan terpilih, jatuh tempo terdekat dulu. */
    public function allocate(): void
    {
        $left = max(0, (int) $this->total);
        $this->alloc = [];
        foreach ($this->bills as $b) {
            $take = in_array($b['id'], array_map('intval', $this->selected), true) ? min($left, $b['remaining']) : 0;
            $this->alloc[$b['id']] = $take;
            $left -= $take;
        }
    }

    public function updatedTotal(): void
    {
        $this->allocate();
    }

    public function updatedSelected(): void
    {
        $this->selected = array_map('intval', $this->selected);
        $this->total = array_sum(array_map(fn ($b) => in_array($b['id'], $this->selected, true) ? $b['remaining'] : 0, $this->bills)) ?: null;
        $this->allocate();
    }

    /** Ubah alokasi manual → total mengikuti jumlah alokasi. */
    public function updatedAlloc(): void
    {
        $this->total = array_sum(array_map(fn ($v) => max(0, (int) $v), $this->alloc));
        $this->selected = array_values(array_map('intval', array_keys(array_filter($this->alloc, fn ($v) => (int) $v > 0))));
    }

    public function save()
    {
        \Illuminate\Support\Facades\Gate::authorize('kelola-pengadaan');
        $rules = [
            'supplierId' => ['required', 'in:'.collect($this->suppliers)->pluck('id')->implode(',')],
            'paidAt' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', 'in:'.implode(',', array_keys(Payables::METHODS))],
            'reference' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
        foreach ($this->bills as $b) {
            $rules["alloc.{$b['id']}"] = ['nullable', 'integer', 'min:0', 'max:'.$b['remaining']];
        }
        try {
            $this->validate($rules, [
                'supplierId.required' => 'Pilih supplier.',
                'paidAt.before_or_equal' => 'Tanggal bayar tidak boleh setelah hari ini.',
                'alloc.*.max' => 'Melebihi sisa tagihan.',
                'alloc.*.*' => 'Isi angka 0 atau lebih.',
            ]);
            if (array_sum(array_map('intval', $this->alloc)) <= 0) {
                throw ValidationException::withMessages(['total' => 'Isi jumlah yang dibayar.']);
            }
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: 'Pembayaran belum tersimpan: periksa isian.');

            throw $e;
        }

        $paidOff = 0;
        $alloc = array_filter(array_map('intval', $this->alloc), fn ($v) => $v > 0);
        app(SupplierBillService::class)->pay($alloc, ['paid_at' => $this->paidAt, 'method' => $this->method,
            'reference' => trim($this->reference) ?: null, 'note' => trim($this->note) ?: null], auth()->user());
        foreach ($this->bills as $b) {
            $paidOff += ($alloc[$b['id']] ?? 0) === $b['remaining'] ? 1 : 0;
        }
        $sum = array_sum(array_map('intval', $this->alloc));
        $name = collect($this->suppliers)->firstWhere('id', (int) $this->supplierId)['name'];
        session()->flash('toast', ['type' => 'success', 'message' => 'Pembayaran Rp'.number_format($sum, 0, ',', '.')." ke {$name} dicatat"
            .($paidOff ? ", {$paidOff} tagihan lunas." : '.')]);

        return $this->redirectRoute('pengadaan.hutang', navigate: true);
    }

    public function render()
    {
        return view('livewire.pengadaan.payment-form');
    }
}
