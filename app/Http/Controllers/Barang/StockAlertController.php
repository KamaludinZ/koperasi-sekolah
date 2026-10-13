<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\MinStockService;
use App\Services\ProductListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stok menipis & batas minimum (JSON):
 * - GET /barang/stok-menipis     → barang aktif habis/menipis + saran restok
 * - GET /barang/batas-minimum    → batas tiap barang + batas bawaan barang baru
 * - PUT /barang/batas-minimum    → { items?: [{product_id, min_stock}], kategori?, nilai?, default_min_stock? }
 */
class StockAlertController extends Controller
{
    public function lowStock(ProductListing $listing): JsonResponse
    {
        $items = $listing->lowStock();

        return response()->json([
            'data' => $items,
            'meta' => [
                'count' => count($items),
                'out_of_stock' => count(array_filter($items, fn ($p) => $p['stock'] <= 0)),
            ],
        ]);
    }

    public function minStocks(MinStockService $minStock): JsonResponse
    {
        return response()->json([
            'data' => Product::with('category:id,name')->orderBy('name')->get()
                ->map(fn (Product $p) => [
                    'product_id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'category' => $p->category?->name,
                    'stock' => $p->stock,
                    'min_stock' => $p->min_stock,
                ]),
            'meta' => ['default_min_stock' => $minStock->defaultMinStock()],
        ]);
    }

    public function updateMinStocks(Request $request, MinStockService $minStock): JsonResponse
    {
        $data = $request->validate([
            'items' => ['nullable', 'array', 'max:1000'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->withoutTrashed()],
            'items.*.min_stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'kategori' => ['nullable', 'string', Rule::exists('categories', 'name')->withoutTrashed()],
            'nilai' => ['nullable', 'integer', 'min:0', 'max:100000', 'required_with:kategori'],
            'default_min_stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $changed = 0;
        if (array_key_exists('nilai', $data) && $data['nilai'] !== null) {
            $changed += $minStock->applyToCategory($data['kategori'] ?? null, $data['nilai']);
        }
        if (! empty($data['items'])) {
            $changed += $minStock->updateMany(collect($data['items'])->pluck('min_stock', 'product_id')->all());
        }
        if (isset($data['default_min_stock'])) {
            $minStock->setDefaultMinStock($data['default_min_stock']);
        }

        return response()->json([
            'message' => 'Batas minimum stok diperbarui.',
            'meta' => ['changed' => $changed, 'default_min_stock' => $minStock->defaultMinStock()],
        ]);
    }
}
