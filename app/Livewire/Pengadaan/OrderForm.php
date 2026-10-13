<?php

namespace App\Livewire\Pengadaan;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use App\Services\SupplierService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengadaan → Pesanan → Buat (Admin & Pengurus): pilih supplier aktif, perkiraan tiba, catatan,
 * lalu daftar barang (cari dari data barang; harga beli bawaan = harga modal). Tombol "isi dari
 * stok menipis" menambahkan barang kategori supplier yang stoknya ≤ batas minimum dengan saran
 * jumlah. Simpan sebagai draf atau langsung tandai dipesan lewat PurchaseOrderService.
 */
#[Layout('components.layouts.app')]
#[Title('Buat Pesanan')]
class OrderForm extends Component
{
    public const MAX_LINES = 50;

    #[Url(as: 'supplier', except: '')]
    public string $supplierId = '';

    public string $expectedAt = '';

    public string $note = '';

    public string $search = '';

    /** @var list<array{product_id: int, code: string, name: string, unit: string, qty: int|string, cost: int|string}> */
    public array $lines = [];

    public function mount(): void
    {
        $this->expectedAt = now()->addDays(3)->toDateString();
    }

    #[Computed]
    public function suppliers(): array
    {
        return app(SupplierService::class)->list(['status' => 'aktif']);
    }

    #[Computed]
    public function supplier(): ?array
    {
        return collect($this->suppliers)->firstWhere('id', (int) $this->supplierId);
    }

    /** Hasil pencarian barang (maks. 8), barang yang sudah ada di daftar tidak ditampilkan. */
    #[Computed]
    public function results(): array
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.mb_strtolower($term).'%';

        return Product::query()->where('is_active', true)
            ->whereNotIn('id', array_column($this->lines, 'product_id'))
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(code) LIKE ?', [$like]))
            ->orderBy('name')->limit(8)->get(['id', 'code', 'name', 'unit', 'stock', 'cost_price'])->toArray();
    }

    #[Computed]
    public function total(): int
    {
        return array_sum(array_map(fn ($l) => max(0, (int) $l['qty']) * max(0, (int) $l['cost']), $this->lines));
    }

    public function addProduct(int $id, ?int $qty = null): void
    {
        $p = Product::find($id);
        if (! $p || in_array($id, array_column($this->lines, 'product_id'), true) || count($this->lines) >= self::MAX_LINES) {
            return;
        }
        $this->lines[] = ['product_id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'unit' => $p->unit,
            'qty' => $qty ?? max($p->min_stock * 2 - $p->stock, 1), 'cost' => (int) $p->cost_price];
        $this->search = '';
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /** Tambahkan barang stok menipis dari kategori yang dipasok supplier terpilih. */
    public function fillLowStock(): void
    {
        if (! $this->supplier) {
            $this->addError('supplierId', 'Pilih supplier dulu.');

            return;
        }
        $before = count($this->lines);
        Product::query()->where('is_active', true)->whereColumn('stock', '<=', 'min_stock')
            ->whereHas('category', fn ($q) => $q->whereIn('name', $this->supplier['categories']))
            ->orderBy('code')->get()->each(fn ($p) => $this->addProduct($p->id));
        $added = count($this->lines) - $before;
        $this->dispatch('toast', type: $added ? 'success' : 'info',
            message: $added ? "{$added} barang stok menipis ditambahkan." : 'Tidak ada barang stok menipis dari kategori supplier ini.');
    }

    protected function rules(): array
    {
        return [
            'supplierId' => ['required', 'in:'.collect($this->suppliers)->pluck('id')->implode(',')],
            'expectedAt' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:'.now()->addDays(91)->toDateString()],
            'note' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.cost' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'supplierId.required' => 'Pilih supplier.',
            'supplierId.in' => 'Pilih supplier yang aktif.',
            'expectedAt.after_or_equal' => 'Perkiraan tiba tidak boleh sebelum hari ini.',
            'expectedAt.before' => 'Perkiraan tiba maksimal 90 hari lagi.',
            'lines.required' => 'Tambahkan minimal satu barang.',
            'lines.min' => 'Tambahkan minimal satu barang.',
            'lines.*.qty.*' => 'Jumlah minimal 1.',
            'lines.*.cost.*' => 'Harga beli harus angka 0 atau lebih.',
        ];
    }

    public function save(string $status = 'draf')
    {
        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: 'Belum tersimpan: periksa isian yang ditandai merah.');

            throw $e;
        }

        $order = app(PurchaseOrderService::class)->create([
            'supplier_id' => (int) $this->supplierId, 'expected_at' => $this->expectedAt, 'note' => trim($this->note) ?: null,
            'status' => $status === 'dipesan' ? 'dipesan' : 'draf',
            'items' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'qty' => (int) $l['qty'], 'cost' => (int) $l['cost']], $this->lines),
        ], auth()->user());
        $order = ['number' => $order->number, 'status' => $order->status, 'supplier' => $order->supplier->name];
        session()->flash('toast', ['type' => 'success', 'message' => "Pesanan {$order['number']} ".($order['status'] === 'dipesan' ? 'dipesan ke '.$order['supplier'].'.' : 'disimpan sebagai draf.')]);

        return $this->redirectRoute('pengadaan.pesanan', navigate: true);
    }

    public function render()
    {
        return view('livewire.pengadaan.order-form');
    }
}
