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
 * - GET  /barang/{product} → detail: data barang, penjualan 30 hari, saran restok, 10 pergerakan stok terakhir
 * - GET  /barang/{product}/riwayat-stok?halaman= → pergerakan stok berhalaman (30 per halaman)
 * - GET  /barang/riwayat-stok/data?barang=&jenis=&dari=&sampai=&halaman= → semua barang + ringkasan
 * Kategori dikirim sebagai nama (`category`). Perubahan stok tercatat di riwayat stok.
 */
class ProductController extends Controller
{
    public function show(Product $product): JsonResponse
    {
        $product->load('category:id,name');
        $sold = \App\Models\TransactionItem::where('product_id', $product->id)
            ->whereHas('transaction', fn ($q) => $q->whereNull('voided_at')->where('created_at', '>=', now()->subDays(30)))
            ->selectRaw('COALESCE(SUM(quantity), 0) AS qty, COALESCE(SUM(subtotal), 0) AS revenue')->first();
        $restock = collect(app(\App\Services\RestockAdvisor::class)->suggestions(14, all: true))->firstWhere('id', $product->id);

        return response()->json(['data' => [
            ...ProductCatalog::toArray($product),
            'sales_30d' => ['quantity' => (int) $sold->qty, 'revenue' => (int) round((float) $sold->revenue)],
            'restock' => $restock ? array_intersect_key($restock, array_flip(['level', 'per_day', 'days_left', 'suggested'])) : null,
            'movements' => $product->stockMovements()->with('user:id,name')->limit(10)->get()->map(fn ($m) => self::movement($m))->all(),
        ]]);
    }

    public function movements(Request $request, Product $product): JsonResponse
    {
        $page = $product->stockMovements()->with('user:id,name')->paginate(30, ['*'], 'halaman');

        return response()->json(['data' => collect($page->items())->map(fn ($m) => self::movement($m))->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    /** GET /barang/riwayat-stok/data?barang=&jenis=&dari=&sampai=&halaman= → semua barang, terbaru dulu, + ringkasan */
    public function allMovements(Request $request, \App\Services\StockMovementQuery $movements): JsonResponse
    {
        $data = $request->validate([
            'barang' => ['nullable', 'integer'],
            'jenis' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Models\StockMovement::TYPES))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ], ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        [$from, $to] = \App\Services\TransactionHistory::range('semua', $data['dari'] ?? null, $data['sampai'] ?? null);
        $f = ['product_id' => $data['barang'] ?? null, 'type' => $data['jenis'] ?? null, 'from' => $from, 'to' => $to];
        $page = $movements->query($f)->with(['product:id,code,name,deleted_at', 'user:id,name'])->latestFirst()->paginate(50, ['*'], 'halaman');

        return response()->json([
            'data' => collect($page->items())->map(fn ($m) => \App\Services\StockMovementQuery::toArray($m))->all(),
            'summary' => $movements->summary($f),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private static function movement(\App\Models\StockMovement $m): array
    {
        return ['id' => $m->id, 'type' => $m->type, 'type_label' => $m->type_label, 'change' => $m->signed_quantity, 'stock_before' => $m->stock_before,
            'stock_after' => $m->stock_after, 'note' => $m->note, 'user' => $m->user?->name, 'created_at' => $m->created_at->toDateTimeString()];
    }

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
