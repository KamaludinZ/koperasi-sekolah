<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Query daftar barang untuk halaman Barang & Stok: filter, urutan, paginasi,
 * ringkasan stok, dan daftar barang yang perlu restok.
 */
class ProductListing
{
    public const STATUSES = ['menipis', 'habis', 'nonaktif'];

    public const SORTS = ['name', 'stock', 'price', 'price_desc'];

    public const PER_PAGE_OPTIONS = [10, 25, 50];

    /**
     * @param  array{search?: string, category?: string, status?: string, sort?: string}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $term = mb_strtolower(trim($filters['search'] ?? ''));
        $category = $filters['category'] ?? '';

        $query = Product::query()
            ->with('category:id,name')
            ->when($category !== '', fn (Builder $q) => $q->whereHas('category', fn (Builder $c) => $c->where('name', $category)))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '\\'", [$like]));
            });

        match ($filters['status'] ?? '') {
            'menipis' => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'min_stock'),
            'habis' => $query->where('stock', '<=', 0),
            'nonaktif' => $query->where('is_active', false),
            default => null,
        };

        match ($filters['sort'] ?? 'name') {
            'stock' => $query->orderBy('stock')->orderBy('name'),
            'price' => $query->orderBy('price')->orderBy('name'),
            'price_desc' => $query->orderByDesc('price')->orderBy('name'),
            default => $query->orderByRaw('LOWER(name)')->orderBy('id'),
        };

        return $query;
    }

    /**
     * Halaman barang sebagai array sederhana (bentuk sama dengan ProductCatalog::toArray).
     */
    public function paginate(array $filters, int $perPage = 10, ?int $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];
        $paginator = $this->query($filters)->paginate($perPage, ['*'], $pageName, $page);

        // Halaman di luar jangkauan (mis. setelah filter) → pakai halaman terakhir.
        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->total() > 0) {
            $paginator = $this->query($filters)->paginate($perPage, ['*'], $pageName, $paginator->lastPage());
        }

        return $paginator->through(fn (Product $p) => ProductCatalog::toArray($p));
    }

    public function stats(): array
    {
        $row = Product::query()->selectRaw('
            COUNT(*) AS total,
            SUM(CASE WHEN stock > 0 AND stock <= min_stock THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
            SUM(CASE WHEN is_active THEN 0 ELSE 1 END) AS inactive,
            SUM(cost_price * stock) AS stock_value
        ')->first();

        return [
            'total' => (int) $row->total,
            'low' => (int) $row->low,
            'out' => (int) $row->out_of_stock,
            'inactive' => (int) $row->inactive,
            'stock_value' => (int) round((float) $row->stock_value),
        ];
    }

    /** Barang aktif yang habis/menipis, paling kritis di atas, dengan saran restok. */
    public function lowStock(): array
    {
        return $this->lowStockQuery()
            ->with('category:id,name')
            ->orderByRaw('CASE WHEN min_stock > 0 THEN stock * 1.0 / min_stock ELSE 0 END')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                ...ProductCatalog::toArray($p),
                // Saran restok: kembalikan stok ke dua kali batas minimum.
                'suggested' => max($p->min_stock * 2 - $p->stock, 1),
            ])
            ->all();
    }

    public function lowStockCount(): int
    {
        return $this->lowStockQuery()->count();
    }

    /** @return list<string> */
    public function categories(): array
    {
        return Category::orderBy('name')->pluck('name')->all();
    }

    private function lowStockQuery(): Builder
    {
        return Product::active()->lowStock();
    }
}
