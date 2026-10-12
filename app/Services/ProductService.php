<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tambah & ubah barang. Perubahan stok selalu lewat StockService
 * sehingga tercatat di riwayat stok.
 */
class ProductService
{
    public const UNITS = ['pcs', 'bks', 'btl', 'kotak', 'lusin', 'rim', 'set'];

    public function __construct(private StockService $stock) {}

    /** Aturan validasi bersama untuk form Livewire & endpoint JSON. */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', function ($attr, $value, $fail) use ($ignoreId) {
                $taken = Product::whereRaw('UPPER(code) = ?', [mb_strtoupper((string) $value)])
                    ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                    ->exists();
                if ($taken) {
                    $fail('Kode barang sudah dipakai barang lain.');
                }
            }],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::exists('categories', 'name')],
            'price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'cost_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'unit' => ['required', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ];
    }

    public static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'integer' => 'Harus berupa angka bulat.',
            'min' => 'Tidak boleh kurang dari :min.',
            'max' => 'Terlalu besar.',
            'code.max' => 'Maksimal :max karakter.',
            'name.max' => 'Maksimal :max karakter.',
            'alpha_dash' => 'Hanya huruf, angka, - dan _.',
            'category.exists' => 'Pilih kategori yang tersedia.',
        ];
    }

    /** @param  array<string, mixed>  $data  data tervalidasi (kategori berupa nama) */
    public function create(array $data, ?int $userId = null): Product
    {
        return DB::transaction(function () use ($data, $userId) {
            $product = Product::create([...$this->attributes($data), 'stock' => 0]);

            if (($data['stock'] ?? 0) > 0) {
                $this->stock->increase($product, (int) $data['stock'], 'Stok awal', $userId);
            }

            return $product->refresh()->load('category');
        });
    }

    /** @param  array<string, mixed>  $data  data tervalidasi (kategori berupa nama) */
    public function update(Product $product, array $data, ?int $userId = null): Product
    {
        return DB::transaction(function () use ($product, $data, $userId) {
            $product->update($this->attributes($data));

            if (array_key_exists('stock', $data)) {
                $this->stock->adjustTo($product, (int) $data['stock'], 'Diubah lewat form barang', $userId);
            }

            return $product->refresh()->load('category');
        });
    }

    private function attributes(array $data): array
    {
        return [
            'code' => mb_strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'category_id' => Category::where('name', $data['category'])->value('id'),
            'price' => $data['price'],
            'cost_price' => $data['cost_price'],
            'min_stock' => $data['min_stock'],
            'unit' => $data['unit'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
