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
    /** Batas bawaan bila pengaturan layar kasir belum ada; MAX_LIMIT = batas tertinggi yang boleh diminta. */
    public const SEARCH_LIMIT = 60;

    public const MAX_LIMIT = 200;

    /**
     * Cari barang aktif berdasarkan nama/kode, opsional per kategori. Urutan: kode/barcode yang cocok persis paling
     * atas (alur scan), lalu barang paling laku (pengaturan Layar kasir: periode hari), lalu nama. Jumlah hasil
     * dibatasi pengaturan Layar kasir (atau $limit).
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term = '', string $category = '', ?int $limit = null): array
    {
        return $this->searchWithTotal($term, $category, $limit)['items'];
    }

    /**
     * Seperti search() ditambah jumlah seluruh barang yang cocok (untuk keterangan "menampilkan N dari M").
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int}
     */
    public function searchWithTotal(string $term = '', string $category = '', ?int $limit = null): array
    {
        $cfg = app(SettingsService::class)->cashier();
        $limit = max(1, min(self::MAX_LIMIT, $limit ?? (int) ($cfg['picker_limit'] ?? self::SEARCH_LIMIT)));
        $term = mb_strtolower(trim($term));

        // kolom ringkas semua barang yang cocok → diurutkan di PHP memakai peringkat terlaris (tersimpan sementara)
        $rows = Product::query()
            ->active()
            ->when($category !== '', fn (Builder $q) => $q->whereHas('category', fn (Builder $c) => $c->where('name', $category)))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw('LOWER(barcode) = ?', [$term])); // barcode dicocokkan persis (hasil scan)
            })
            ->get(['id', 'name', 'code', 'barcode']);
        $rank = app(BestSellers::class)->ranking((int) ($cfg['bestseller_days'] ?? 30));
        $exact = fn ($p) => $term !== '' && (mb_strtolower((string) $p->code) === $term || mb_strtolower((string) $p->barcode) === $term) ? 0 : 1;
        $ids = $rows->sort(fn ($a, $b) => [$exact($a), -($rank[$a->id] ?? 0), mb_strtolower($a->name), $a->id]
            <=> [$exact($b), -($rank[$b->id] ?? 0), mb_strtolower($b->name), $b->id])
            ->take($limit)->pluck('id')->all();
        $models = Product::with('category:id,name')->whereKey($ids)->get()->keyBy('id');

        return [
            'items' => array_values(array_filter(array_map(fn ($id) => isset($models[$id]) ? self::toArray($models[$id]) + ['sold' => $rank[$id] ?? 0] : null, $ids))),
            'total' => $rows->count(),
            'limit' => $limit,
        ];
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
            'barcode' => $p->barcode,                          // barcode khusus (null = ikut kode)
            'barcode_value' => $p->barcode ?: $p->code,        // nilai yang tercetak di label & cocok saat scan
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
