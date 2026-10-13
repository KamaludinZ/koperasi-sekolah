<?php

namespace App\Livewire\Barang;

use App\Support\MockData;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Barang → Kategori & impor: halaman utama pengelolaan katalog. Daftar kategori (urutan, jumlah
 * barang, stok menipis, nilai stok) dan pintu masuk impor massal barang dari CSV/Excel beserta
 * riwayat impor terbaru. Tahap ini memakai MockData::categoryOverview() & importHistory();
 * backend menyusul dengan kontrak yang sama.
 */
#[Layout('components.layouts.app')]
#[Title('Kategori & Impor')]
class Catalog extends Component
{
    /** Status impor → [label, kelas lencana]. */
    public const IMPORT_STATUSES = [
        'pratinjau' => ['Menunggu diperiksa', 'bg-sky-100 text-sky-800'],
        'selesai' => ['Selesai', 'bg-emerald-100 text-emerald-800'],
        'sebagian' => ['Sebagian tersimpan', 'bg-amber-100 text-amber-900'],
        'gagal' => ['Gagal', 'bg-red-100 text-red-800'],
    ];

    /** Warna titik kategori. */
    public const COLORS = [
        'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-400', 'sky' => 'bg-sky-500',
        'violet' => 'bg-violet-500', 'teal' => 'bg-teal-500', 'rose' => 'bg-rose-500', 'stone' => 'bg-stone-400',
    ];

    #[Computed]
    public function categories(): array
    {
        return MockData::categoryOverview();
    }

    #[Computed]
    public function imports(): array
    {
        return MockData::importHistory();
    }

    #[Computed]
    public function summary(): array
    {
        $cats = collect($this->categories);
        $imports = collect($this->imports);
        $recent = $imports->filter(fn ($i) => $i['created_at'] >= now()->subDays(30)->toDateTimeString());

        return [
            'categories' => $cats->count(),
            'empty' => $cats->where('products', 0)->count(),
            'products' => $cats->sum('products'),
            'stock_value' => $cats->sum('stock_value'),
            'imports_30d' => $recent->count(),
            'imported_rows_30d' => $recent->sum(fn ($i) => $i['created'] + $i['updated']),
            'failed_rows_30d' => $recent->sum('failed'),
            'pending' => $imports->firstWhere('status', 'pratinjau'),
        ];
    }

    /** Tautan ke halaman lanjutan bila sudah tersedia (null = segera hadir). */
    public static function link(string $route, array $params = []): ?string
    {
        return Route::has($route) ? route($route, $params) : null;
    }

    public function render()
    {
        return view('livewire.barang.catalog');
    }
}
