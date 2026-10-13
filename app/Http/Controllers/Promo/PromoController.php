<?php

namespace App\Http\Controllers\Promo;

use App\Http\Controllers\Controller;
use App\Livewire\Promo\Index;
use App\Livewire\Promo\Schedule;
use App\Models\Promo;
use App\Services\PromoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GET /promo/data?status=aktif|terjadwal|berakhir|nonaktif|semua&q=
 *     Promo (status, aturan, jadwal, pemakaian & potongan), jumlah per status, kupon (sisa kuota),
 *     angka ringkasan, dan aturan diskon manual.
 * GET /promo/jadwal/data?dari=&sampai=   (bawaan: 4 minggu lalu s.d. 8 minggu ke depan, maks. 366 hari)
 *     Linimasa promo & kupon (posisi batang, bentrok) + dampak promo yang sudah mulai.
 * GET /promo/{promo}/dampak
 *     Dampak satu promo: penjualan harian sebelum vs selama, potongan, efek bersih.
 */
class PromoController extends Controller
{
    public function index(Request $request, PromoService $promos): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Index::STATUSES))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $all = $promos->all();
        $status = $data['status'] ?? 'semua';
        $q = mb_strtolower(trim($data['q'] ?? ''));
        $coupons = $promos->coupons();

        return response()->json([
            'data' => array_values(array_filter($all, fn ($p) => ($status === 'semua' || $p['status'] === $status)
                && ($q === '' || str_contains(mb_strtolower($p['name'].' '.implode(' ', $p['targets'])), $q)))),
            'counts' => collect(Index::STATUSES)->keys()->mapWithKeys(fn ($s) => [$s => $s === 'semua' ? count($all) : collect($all)->where('status', $s)->count()]),
            'coupons' => $coupons,
            'stats' => $promos->stats($all, $coupons),
            'manual_discount' => $promos->manualRules(),
        ]);
    }

    public function schedule(Request $request, PromoService $promos): JsonResponse
    {
        $data = $request->validate([
            'dari' => ['nullable', 'required_with:sampai', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'required_with:dari', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ], ['sampai.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);

        $from = isset($data['dari']) ? Carbon::parse($data['dari']) : today()->subDays(28)->startOfWeek();
        $to = isset($data['sampai']) ? Carbon::parse($data['sampai']) : $from->copy()->addDays(Schedule::WINDOW_DAYS - 1);
        if ($from->diffInDays($to) + 1 > 366) {
            return response()->json(['message' => 'Rentang maksimal 366 hari.', 'errors' => ['dari' => ['Rentang maksimal 366 hari.']]], 422);
        }

        return response()->json([
            'data' => $promos->timeline($from, $to),
            'impacts' => $promos->impacts(),
            'meta' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'today' => today()->toDateString()],
        ]);
    }

    public function impact(Promo $promo, PromoService $promos): JsonResponse
    {
        $row = collect($promos->all())->firstWhere('id', $promo->id);

        return response()->json(['data' => [
            'promo' => $row,
            'impact' => $promo->starts_on->lte(today()) ? $promos->impact($row) : null,
        ]]);
    }
}
