<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pencarian & pengambilan barang untuk layar kasir.
 * Mengembalikan array sederhana (bukan model) agar aman disimpan di state Livewire.
 */
class ProductCatalog
{
    public const SEARCH_LIMIT = 60;

    /**
     * Cari barang aktif berdasarkan nama/kode, opsional per kategori.
     * Kode yang cocok persis diletakkan paling atas (alur scan barcode).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term = '', string $category = '', int $limit = self::SEARCH_LIMIT): array
    {
        $term = mb_strtolower(trim($term));

        return Product::query()
            ->active()
            ->with('category:id,name')
            ->when($category !== '', fn (Builder $q) => $q->whereHas('category', fn (Builder $c) => $c->where('name', $category)))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->when($term !== '', fn (Builder $q) => $q->orderByRaw('CASE WHEN LOWER(code) = ? THEN 0 ELSE 1 END', [$term]))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Product $p) => self::toArray($p))
            ->all();
    }

    /** Cari satu barang aktif berdasarkan kode persis (tidak peka huruf besar/kecil). */
    public function findByCode(string $code): ?array
    {
        $code = mb_strtolower(trim($code));
        // Kode barang atau nilai barcode (hasil scan); keduanya unik lintas barang.
        $product = Product::active()->with('category:id,name')
            ->where(fn ($q) => $q->whereRaw('LOWER(code) = ?', [$code])->orWhereRaw('LOWER(barcode) = ?', [$code]))
            ->first();

        return $product ? self::toArray($product) : null;
    }

    /**
     * Ambil barang aktif berdasarkan id, di-key per id.
     *
     * @param  array<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::active()->with('category:id,name')->whereKey($ids)->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => self::toArray($p)])
            ->all();
    }

    public function find(int $id): ?array
    {
        return $this->findMany([$id])[$id] ?? null;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return Category::ordered()->pluck('name')->all();
    }

    public static function toArray(Product $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'category' => $p->category?->name,
            'price' => (int) round((float) $p->price),
            'cost_price' => (int) round((float) $p->cost_price),
            'stock' => $p->stock,
            'min_stock' => $p->min_stock,
            'unit' => $p->unit,
            'is_active' => $p->is_active,
        ];
    }
}
