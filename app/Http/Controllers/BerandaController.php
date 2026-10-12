<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Member;
use App\Models\Product;
use App\Services\SalesReport;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * GET / — beranda publik koperasi untuk siswa, guru, dan wali: identitas,
 * status buka hari ini, layanan, barang populer, kategori, jam buka & lokasi.
 * Hanya menampilkan informasi umum (tanpa saldo atau data pribadi).
 */
class BerandaController extends Controller
{
    /** Jam buka per hari ISO (1 = Senin … 7 = Minggu). Nanti dari pengaturan (fase 4). */
    public const OPENING_HOURS = [
        1 => ['07:00', '14:00'], 2 => ['07:00', '14:00'], 3 => ['07:00', '14:00'], 4 => ['07:00', '14:00'],
        5 => ['07:00', '11:00'], 6 => ['07:00', '12:00'],
    ];

    public function __invoke(SalesReport $report): View
    {
        return view('beranda', [
            'profile' => MockData::profile(),
            'status' => self::openStatus(now()),
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
    public static function openStatus(Carbon $now): array
    {
        $today = self::OPENING_HOURS[$now->dayOfWeekIso] ?? null;
        $time = $now->format('H:i');

        if ($today && $time >= $today[0] && $time < $today[1]) {
            return ['open' => true, 'label' => 'Sedang buka', 'detail' => 'Tutup pukul '.str_replace(':', '.', $today[1])];
        }

        // Cari hari buka berikutnya.
        for ($i = 0; $i <= 7; $i++) {
            $day = $now->copy()->addDays($i);
            $hours = self::OPENING_HOURS[$day->dayOfWeekIso] ?? null;
            if ($hours && ($i > 0 || $time < $hours[0])) {
                $when = $i === 0 ? 'hari ini' : ($i === 1 ? 'besok' : $day->translatedFormat('l'));

                return ['open' => false, 'label' => 'Sedang tutup', 'detail' => "Buka {$when} pukul ".str_replace(':', '.', $hours[0])];
            }
        }

        return ['open' => false, 'label' => 'Sedang tutup', 'detail' => ''];
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
