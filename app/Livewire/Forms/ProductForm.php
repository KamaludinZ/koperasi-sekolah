<?php

namespace App\Livewire\Forms;

use App\Support\MockData;
use Illuminate\Validation\Rule;
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

    public const UNITS = ['pcs', 'bks', 'btl', 'kotak', 'lusin', 'rim', 'set'];

    protected function rules(): array
    {
        $codes = collect(MockData::products())
            ->reject(fn ($p) => $p['id'] === $this->id)
            ->pluck('code')
            ->map(fn ($c) => mb_strtoupper($c))
            ->all();

        return [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', function ($attr, $value, $fail) use ($codes) {
                if (in_array(mb_strtoupper($value), $codes, true)) {
                    $fail('Kode barang sudah dipakai barang lain.');
                }
            }],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(MockData::categories())],
            'price' => ['required', 'integer', 'min:0'],
            'cost_price' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'unit' => ['required', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'integer' => 'Harus berupa angka bulat.',
            'min' => 'Tidak boleh kurang dari :min.',
            'max' => 'Maksimal :max karakter.',
            'alpha_dash' => 'Hanya huruf, angka, - dan _.',
            'category.in' => 'Pilih kategori yang tersedia.',
        ];
    }

    /** Isi form dari data barang yang akan diubah. */
    /** Kosongkan form untuk barang baru, memakai batas minimum bawaan. */
    public function blank(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->min_stock = MockData::defaultMinStock();
    }

    public function load(array $product): void
    {
        $this->reset();
        $this->resetValidation();
        $this->fill(collect($product)->only(array_keys($this->all()))->all());
    }

    public function save(): array
    {
        $data = $this->validate();
        $data['code'] = mb_strtoupper($data['code']);

        return MockData::saveProduct(['id' => $this->id, ...$data]);
    }
}
