<?php

namespace App\Http\Controllers\Barang;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kategori barang (JSON). Melihat: semua pemegang menu Barang; mengubah: Admin & Pengurus
 * (gate kelola-katalog).
 * - GET    /barang/kategori/data            → daftar berurutan + jumlah barang, stok menipis, nilai stok
 * - POST   /barang/kategori                 → {name, color?, description?} (201)
 * - PUT    /barang/kategori/urutan          → {ids: [..]} urutan baru
 * - PUT    /barang/kategori/{category}      → ubah nama/warna/keterangan
 * - DELETE /barang/kategori/{category}      → {pindah_ke?, alasan?} hapus lunak, barang dipindah
 */
class CategoryController extends Controller
{
    public function index(CategoryService $categories): JsonResponse
    {
        return response()->json(['data' => $categories->list(), 'colors' => Category::COLORS]);
    }

    public function store(Request $request, CategoryService $categories): JsonResponse
    {
        $data = $request->validate(CategoryService::rules(), CategoryService::messages());

        return response()->json(['data' => CategoryService::toArray($categories->create($data))], 201);
    }

    public function update(Request $request, Category $category, CategoryService $categories): JsonResponse
    {
        $data = $request->validate(CategoryService::rules($category->id), CategoryService::messages());

        return response()->json(['data' => CategoryService::toArray($categories->update($category, $data))]);
    }

    public function destroy(Request $request, Category $category, CategoryService $categories): JsonResponse
    {
        $data = $request->validate([
            'pindah_ke' => ['nullable', 'integer', Rule::exists('categories', 'id')->withoutTrashed()],
            'alasan' => ['nullable', 'string', 'max:200'],
        ], ['pindah_ke.exists' => 'Kategori tujuan tidak ditemukan.']);

        $target = $categories->delete($category, $data['pindah_ke'] ?? null, $data['alasan'] ?? null);

        return response()->json(['message' => "Kategori {$category->name} dihapus".($target ? ", barangnya dipindah ke {$target->name}." : '.')]);
    }

    public function reorder(Request $request, CategoryService $categories): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->withoutTrashed()],
        ]);
        $categories->reorder($data['ids']);

        return response()->json(['data' => $categories->list()]);
    }
}
