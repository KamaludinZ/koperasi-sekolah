<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Services\ProductListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /barang/daftar?q=&kategori=&status=&urut=&per_halaman=&page=
 * Daftar barang berpaginasi (JSON) + ringkasan stok.
 */
class ProductListController extends Controller
{
    public function __invoke(Request $request, ProductListing $listing): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kategori' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ProductListing::STATUSES)],
            'urut' => ['nullable', Rule::in(ProductListing::SORTS)],
            'per_halaman' => ['nullable', 'integer', Rule::in(ProductListing::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $listing->paginate([
            'search' => $data['q'] ?? '',
            'category' => $data['kategori'] ?? '',
            'status' => $data['status'] ?? '',
            'sort' => $data['urut'] ?? 'name',
        ], (int) ($data['per_halaman'] ?? ProductListing::PER_PAGE_OPTIONS[0]))
            ->withQueryString();

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'stats' => $listing->stats(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }
}
