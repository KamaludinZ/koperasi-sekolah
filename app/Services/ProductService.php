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

            $product->refresh()->load('category');
            AuditLog::record('product.created', 'barang', "Barang baru {$product->name} ({$product->code}), stok awal {$product->stock} {$product->unit}",
                self::subject($product), ['price' => (int) $product->price, 'cost_price' => (int) $product->cost_price, 'stock' => $product->stock, 'category' => $product->category?->name]);

            return $product;
        });
    }

    /** @param  array<string, mixed>  $data  data tervalidasi (kategori berupa nama) */
    public function update(Product $product, array $data, ?int $userId = null): Product
    {
        return DB::transaction(function () use ($product, $data, $userId) {
            $fields = ['code', 'name', 'category_id', 'price', 'cost_price', 'min_stock', 'unit', 'is_active'];
            $before = $product->only($fields);
            $product->update($this->attributes($data));
            $changes = AuditLog::diff($before, $product->only($fields), $fields);

            $movement = array_key_exists('stock', $data)
                ? $this->stock->adjustTo($product, (int) $data['stock'], 'Diubah lewat form barang', $userId)
                : null;

            $product->refresh()->load('category');
            if ($changes) {
                AuditLog::record('product.updated', 'barang', "Data barang {$product->name} diubah: ".implode(', ', array_keys($changes)),
                    self::subject($product), ['changes' => $changes]);
            }
            if ($movement) {
                AuditLog::record('stock.adjusted', 'stok',
                    "Stok {$product->name} disesuaikan {$movement->stock_before} → {$movement->stock_after} {$product->unit}",
                    self::subject($product), ['before' => $movement->stock_before, 'after' => $movement->stock_after, 'note' => $movement->note]);
            }

            return $product;
        });
    }

    private static function subject(Product $product): array
    {
        return ['type' => 'product', 'id' => $product->id, 'label' => "{$product->code} · {$product->name}"];
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
