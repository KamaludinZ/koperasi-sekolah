<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductCatalog;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tambah & ubah barang (JSON):
 * - POST /barang          → barang baru (201)
 * - PUT  /barang/{product} → perbarui barang
 * Kategori dikirim sebagai nama (`category`). Perubahan stok tercatat di riwayat stok.
 */
class ProductController extends Controller
{
    public function store(Request $request, ProductService $products): JsonResponse
    {
        $data = $request->validate(ProductService::rules(), ProductService::messages());

        $product = $products->create($data, $request->user()?->id);

        return response()->json(['data' => ProductCatalog::toArray($product)], 201);
    }

    public function update(Request $request, Product $product, ProductService $products): JsonResponse
    {
        $data = $request->validate(ProductService::rules($product->id), ProductService::messages());

        $product = $products->update($product, $data, $request->user()?->id);

        return response()->json(['data' => ProductCatalog::toArray($product)]);
    }
}
