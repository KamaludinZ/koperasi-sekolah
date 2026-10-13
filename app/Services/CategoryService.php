<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kelola kategori barang: daftar berurutan dengan jumlah barang, stok menipis & nilai stok;
 * tambah/ubah (nama unik di antara kategori aktif, warna, keterangan); hapus lunak dengan
 * memindahkan barangnya ke kategori lain; dan simpan urutan baru. Semua perubahan tercatat
 * di audit (MasterDataObserver; pemindahan barang per barang).
 */
class CategoryService
{
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:50', function ($attr, $value, $fail) use ($ignoreId) {
                $taken = Category::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $value)))])
                    ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
                if ($taken) {
                    $fail('Nama kategori sudah dipakai.');
                }
            }],
            'color' => ['sometimes', 'required', Rule::in(Category::COLORS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public static function messages(): array
    {
        return [
            'name.required' => 'Isi nama kategori.',
            'name.min' => 'Nama kategori minimal 2 huruf.',
            'name.max' => 'Nama kategori maksimal 50 huruf.',
            'color.in' => 'Warna tidak tersedia.',
        ];
    }

    /**
     * @return list<array{id: int, name: string, color: string, position: int, description: ?string, products: int, active_products: int, low_stock: int, stock_value: int, updated_at: string}>
     */
    public function list(): array
    {
        $stats = Product::query()->toBase()->whereNull('deleted_at')->whereNotNull('category_id')->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS products,
                SUM(CASE WHEN is_active THEN 1 ELSE 0 END) AS active_products,
                SUM(CASE WHEN is_active AND stock <= min_stock THEN 1 ELSE 0 END) AS low_stock,
                COALESCE(SUM(stock * cost_price), 0) AS stock_value')
            ->get()->keyBy('category_id');

        return Category::ordered()->get()->map(fn (Category $c) => self::toArray($c, $stats[$c->id] ?? null))->all();
    }

    public static function toArray(Category $c, ?object $stat = null): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'color' => $c->color,
            'position' => $c->position,
            'description' => $c->description,
            'products' => (int) ($stat->products ?? 0),
            'active_products' => (int) ($stat->active_products ?? 0),
            'low_stock' => (int) ($stat->low_stock ?? 0),
            'stock_value' => (int) round((float) ($stat->stock_value ?? 0)),
            'updated_at' => $c->updated_at?->toDateTimeString(),
        ];
    }

    private static function clean(array $data): array
    {
        return collect($data)->only(['name', 'color', 'description'])
            ->map(fn ($v, $k) => match ($k) {
                'name' => preg_replace('/\s+/', ' ', trim($v)),
                'description' => filled($v) ? trim($v) : null,
                default => $v,
            })->all();
    }

    public function create(array $data): Category
    {
        $data = self::clean($data);
        $data['color'] ??= collect(Category::COLORS)->first(fn ($c) => ! Category::where('color', $c)->exists()) ?? 'stone';

        return Category::create($data);
    }

    public function update(Category $category, array $data): Category
    {
        $category->update(self::clean($data));

        return $category->refresh();
    }

    /**
     * Hapus lunak kategori. Barang di dalamnya wajib dipindah ke kategori lain (aktif).
     *
     * @throws ValidationException
     */
    public function delete(Category $category, ?int $moveTo = null, ?string $reason = null): ?Category
    {
        $count = Product::withTrashed()->where('category_id', $category->id)->count();
        $target = $moveTo ? Category::find($moveTo) : null;
        if ($count > 0 && (! $target || $target->is($category))) {
            throw ValidationException::withMessages(['pindah_ke' => "Pilih kategori tujuan untuk {$count} barang di dalamnya."]);
        }

        DB::transaction(function () use ($category, $target, $reason) {
            if ($target) {
                $reason ??= "Kategori {$category->name} dihapus";
                AuditLog::withReason("Dipindah dari kategori {$category->name}", fn () => Product::withTrashed()
                    ->where('category_id', $category->id)->get()->each(fn (Product $p) => $p->update(['category_id' => $target->id])));
            }
            $category->trash($reason);
        });

        return $target;
    }

    /**
     * Simpan urutan baru dari daftar id (yang tidak disebut ditaruh di belakang, urutan lama).
     *
     * @param  list<int>  $ids
     */
    public function reorder(array $ids): void
    {
        $ordered = collect($ids)->map(fn ($id) => (int) $id)->unique()
            ->concat(Category::ordered()->whereNotIn('id', $ids)->pluck('id'))->values();
        $before = Category::ordered()->pluck('name')->all();

        DB::transaction(fn () => $ordered->each(fn ($id, $i) => Category::whereKey($id)->update(['position' => $i + 1])));

        $after = Category::ordered()->pluck('name')->all();
        if ($before !== $after) {
            AuditLog::record('category.reordered', 'barang', 'Urutan kategori diubah: '.implode(', ', $after), null,
                [], action: 'diubah', old: ['urutan' => $before], new: ['urutan' => $after]);
        }
    }
}
