<?php

namespace App\Http\Controllers\Kasir;

use App\Http\Controllers\Controller;
use App\Services\ProductCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /kasir/barang/cari?q=&kategori=&kode=
 * Pencarian barang aktif untuk kasir (JSON). Dengan `kode`, mengembalikan satu
 * barang yang kodenya cocok persis (alur scanner barcode) atau 404.
 */
class ProductSearchController extends Controller
{
    public function __invoke(Request $request, ProductCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'kode' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ProductCatalog::SEARCH_LIMIT],
        ]);

        if (filled($validated['kode'] ?? null)) {
            $product = $catalog->findByCode($validated['kode']);

            return $product
                ? response()->json(['data' => $product])
                : response()->json(['message' => 'Barang dengan kode tersebut tidak ditemukan.'], 404);
        }

        $products = $catalog->search(
            $validated['q'] ?? '',
            $validated['kategori'] ?? '',
            $validated['limit'] ?? ProductCatalog::SEARCH_LIMIT,
        );

        return response()->json(['data' => $products, 'meta' => ['count' => count($products)]]);
    }
}
