<?php

namespace App\Livewire\Forms;

use App\Models\Product;
use App\Services\MinStockService;
use App\Services\ProductCatalog;
use App\Services\ProductService;
use Livewire\Form;

/**
 * Form tambah/ubah barang. Kolom mengikuti tabel products di PRD.
 */
class ProductForm extends Form
{
    public ?int $id = null;

    public string $code = '';

    public string $name = '';

    public string $category = '';

    public ?int $price = null;

    public ?int $cost_price = null;

    public ?int $stock = 0;

    public ?int $min_stock = 5;

    public string $unit = 'pcs';

    public bool $is_active = true;

    public const UNITS = ProductService::UNITS;

    protected function rules(): array
    {
        return ProductService::rules($this->id);
    }

    protected function messages(): array
    {
        return ProductService::messages();
    }

    /** Kosongkan form untuk barang baru, memakai batas minimum bawaan. */
    public function blank(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->min_stock = app(MinStockService::class)->defaultMinStock();
    }

    /** Isi form dari data barang yang akan diubah. */
    public function load(array $product): void
    {
        $this->reset();
        $this->resetValidation();
        $this->fill(collect($product)->only(array_keys($this->all()))->all());
    }

    /** Simpan ke database; kembalikan barang tersimpan sebagai array. */
    public function save(): array
    {
        $data = $this->validate();
        $service = app(ProductService::class);

        $product = $this->id
            ? $service->update(Product::findOrFail($this->id), $data, auth()->id())
            : $service->create($data, auth()->id());

        return ProductCatalog::toArray($product);
    }
}
