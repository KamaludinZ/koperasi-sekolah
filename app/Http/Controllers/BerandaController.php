<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Member;
use App\Models\Product;
use App\Services\SalesReport;
use App\Support\MockData;
use App\Support\OpeningHours;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * GET / — beranda publik koperasi untuk siswa, guru, dan wali: identitas,
 * status buka hari ini, layanan, barang populer, kategori, jam buka & lokasi.
 * Hanya menampilkan informasi umum (tanpa saldo atau data pribadi).
 */
class BerandaController extends Controller
{
    public function __invoke(SalesReport $report): View
    {
        return view('beranda', [
            'profile' => MockData::profile(),
            'status' => self::openStatus(now()),
            'announcements' => MockData::publicAnnouncements(),
            'popular' => $this->popular($report),
            'categories' => Category::withCount(['products' => fn ($q) => $q->active()])->orderBy('name')->get()
                ->filter(fn ($c) => $c->products_count > 0)->values(),
            'stats' => [
                'products' => Product::active()->where('stock', '>', 0)->count(),
                'members' => Member::active()->count(),
                'categories' => Category::count(),
            ],
        ]);
    }

    /** @return array{open: bool, label: string, detail: string} */
    public static function openStatus(Carbon $now, ?array $schedule = null): array
    {
        return OpeningHours::status($schedule ?? MockData::profile()['schedule'], $now);
    }

    /** Barang terlaris 30 hari terakhir yang masih tersedia (cadangan: barang aktif). */
    private function popular(SalesReport $report): array
    {
        $names = collect($report->topProducts(now()->subDays(29)->startOfDay(), now(), 12))->pluck('name');

        $products = Product::active()->with('category:id,name')->whereIn('name', $names)->get()
            ->sortBy(fn ($p) => $names->search($p->name))
            ->values();

        if ($products->count() < 6) {
            $products = $products->merge(
                Product::active()->with('category:id,name')->whereNotIn('id', $products->pluck('id'))
                    ->orderByDesc('stock')->limit(6 - $products->count())->get()
            );
        }

        return $products->take(6)->map(fn (Product $p) => [
            'name' => $p->name,
            'category' => $p->category?->name,
            'price' => (int) round((float) $p->price),
            'unit' => $p->unit,
            'availability' => $p->stock <= 0 ? 'habis' : ($p->stock <= $p->min_stock ? 'terbatas' : 'tersedia'),
        ])->all();
    }
}
