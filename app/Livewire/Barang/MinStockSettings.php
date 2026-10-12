<?php

namespace App\Livewire\Barang;

use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Dialog pengaturan batas minimum stok: ubah banyak barang sekaligus,
 * terapkan satu nilai ke satu kategori, dan atur batas bawaan barang baru.
 * Mengirim event `min-stock-updated` agar daftar barang memuat ulang.
 */
class MinStockSettings extends Component
{
    public bool $open = false;

    /** @var array<int, int|string|null> [productId => min_stock] */
    public array $minStocks = [];

    public ?int $defaultMinStock = null;

    public string $bulkCategory = '';

    public ?int $bulkValue = null;

    public string $filter = '';

    #[On('open-min-stock-settings')]
    public function show(): void
    {
        $this->resetValidation();
        $this->minStocks = collect(MockData::products())->pluck('min_stock', 'id')->all();
        $this->defaultMinStock = MockData::defaultMinStock();
        $this->reset('bulkCategory', 'bulkValue', 'filter');
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** Isi nilai yang sama ke semua barang dalam kategori (atau semua barang). Belum disimpan. */
    public function applyBulk(): void
    {
        $this->validate(['bulkValue' => ['required', 'integer', 'min:0']], [
            'bulkValue.required' => 'Isi angka batasnya dulu.',
            'bulkValue.min' => 'Tidak boleh negatif.',
        ]);

        foreach (MockData::products() as $p) {
            if ($this->bulkCategory === '' || $p['category'] === $this->bulkCategory) {
                $this->minStocks[$p['id']] = $this->bulkValue;
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'minStocks' => ['array'],
            'minStocks.*' => ['required', 'integer', 'min:0', 'max:100000'],
            'defaultMinStock' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [
            'minStocks.*.required' => 'Wajib diisi.',
            'minStocks.*.integer' => 'Harus angka.',
            'minStocks.*.min' => 'Min. 0.',
            'defaultMinStock.required' => 'Wajib diisi.',
            'defaultMinStock.min' => 'Tidak boleh negatif.',
        ]);

        MockData::saveMinStocks($this->minStocks);
        MockData::setDefaultMinStock($this->defaultMinStock);

        $this->open = false;
        $this->dispatch('min-stock-updated', message: 'Batas minimum stok diperbarui.');
    }

    #[Computed]
    public function rows(): array
    {
        $term = mb_strtolower(trim($this->filter));

        return collect(MockData::products())
            ->filter(fn ($p) => $term === ''
                || str_contains(mb_strtolower($p['name']), $term)
                || str_contains(mb_strtolower($p['code']), $term)
                || mb_strtolower($p['category']) === $term)
            ->sortBy([['category', 'asc'], ['name', 'asc']])
            ->groupBy('category')
            ->all();
    }

    public function render()
    {
        return view('livewire.barang.min-stock-settings', [
            'categories' => MockData::categories(),
        ]);
    }
}
