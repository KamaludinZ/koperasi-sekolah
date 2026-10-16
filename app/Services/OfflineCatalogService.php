<?php

namespace App\Services;

use App\Models\CashShift;
use App\Models\PosOfflineCatalog;
use App\Models\Product;
use App\Models\User;
use App\Support\MockData;

/**
 * Salinan katalog untuk kasir offline: barang aktif (harga, stok, kode/barcode, kategori), urutan terlaris, pajak &
 * pembulatan, identitas struk, dan shift kas yang sedang terbuka. Setiap unduhan dicatat (pos_offline_catalogs) —
 * harga di dalamnya menjadi acuan saat penjualan offline disinkronkan, sehingga harga yang diubah di perangkat ditolak.
 */
class OfflineCatalogService
{
    public const VALID_DAYS = 7;

    /** Metode bayar yang boleh dipakai offline (tidak butuh saldo/batas yang dicek di server). */
    public const PAYMENT_METHODS = ['tunai' => 'Tunai', 'qris' => 'QRIS / Transfer'];

    public function __construct(private SettingsService $settings, private BestSellers $bestSellers) {}

    /** @return array<string, mixed> */
    public function issue(User $user, string $deviceId): array
    {
        $products = Product::active()->with('category:id,name')->orderBy('name')->get();
        $tax = $this->settings->tax();
        $cashier = $this->settings->cashier();
        $catalog = PosOfflineCatalog::create([
            'user_id' => $user->id, 'device_id' => $deviceId,
            'prices' => $products->mapWithKeys(fn ($p) => [$p->id => (int) round((float) $p->price)])->all(),
            'tax' => $tax, 'product_count' => $products->count(),
            'issued_at' => now(), 'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);
        $shift = CashShift::open()->first(['id', 'name', 'opened_at']);
        $profile = MockData::profile();
        $receipt = $this->settings->section('receipt');

        return [
            'catalog_id' => $catalog->id,
            'issued_at' => $catalog->issued_at->toIso8601String(),
            'expires_at' => $catalog->expires_at->toIso8601String(),
            'user' => ['id' => $user->id, 'name' => $user->name],
            'device_id' => $deviceId,
            'products' => $products->map(fn ($p) => [
                'id' => $p->id, 'code' => $p->code, 'barcode' => $p->barcode ?: $p->code, 'name' => $p->name, 'category' => $p->category?->name,
                'price' => (int) round((float) $p->price), 'stock' => (int) $p->stock, 'unit' => $p->unit, 'min_stock' => (int) $p->min_stock,
            ])->values()->all(),
            'best_sellers' => $this->bestSellers->top((int) $cashier['bestseller_days'], (int) $cashier['bestseller_badges']),
            'ranking' => array_slice($this->bestSellers->ranking((int) $cashier['bestseller_days']), 0, 500, true),
            'picker_limit' => (int) $cashier['picker_limit'],
            'tax' => $tax,
            'payment_methods' => self::PAYMENT_METHODS,
            'shift' => $shift ? ['id' => $shift->id, 'name' => $shift->name, 'opened_at' => $shift->opened_at?->toIso8601String()] : null,
            'receipt' => [
                'title' => $receipt['header'] ?: ($profile['short_name'] ?? $profile['name'] ?? 'Koperasi'),
                'subtitle' => $receipt['subheader'] ?: ($profile['school'] ?? null),
                'address' => $receipt['show_address'] ? ($profile['address'] ?? null) : null,
                'phone' => $receipt['show_phone'] ? ($profile['phone'] ?? null) : null,
                'footer' => $receipt['footer'], 'paper' => $receipt['paper'],
            ],
        ];
    }
}
