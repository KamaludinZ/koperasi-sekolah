<?php

namespace App\Support;

/**
 * Hitung total belanja dari subtotal + pengaturan pajak & pembulatan:
 * - pajak dipungut di atas harga, atau sudah termasuk harga (hanya dirinci);
 * - pembulatan total ke atas/terdekat/ke bawah per Rp100/500/1.000, bisa hanya untuk tunai.
 * Hasil: subtotal, tax (yang ditambahkan), tax_included (rincian pajak di dalam harga),
 * total (sebelum pembulatan), rounding (selisih, bisa negatif), payable.
 */
class PriceCalculator
{
    public const ROUNDING = [
        'none' => 'Tanpa pembulatan',
        'up' => 'Selalu ke atas',
        'nearest' => 'Ke nilai terdekat',
        'down' => 'Selalu ke bawah',
    ];

    public const UNITS = [100, 500, 1000];

    /**
     * @param  array{enabled?: bool, rate?: float|int, included?: bool, rounding?: string, rounding_unit?: int, cash_only?: bool}  $tax
     * @return array{subtotal: int, tax: int, tax_included: int, total: int, rounding: int, payable: int}
     */
    public static function compute(int $subtotal, array $tax, string $paymentMethod = 'tunai'): array
    {
        $rate = ($tax['enabled'] ?? false) ? max(0, (float) ($tax['rate'] ?? 0)) : 0.0;
        $included = (bool) ($tax['included'] ?? false);

        $added = $rate > 0 && ! $included ? (int) round($subtotal * $rate / 100) : 0;
        $inside = $rate > 0 && $included ? (int) round($subtotal - $subtotal / (1 + $rate / 100)) : 0;
        $total = $subtotal + $added;

        $mode = $tax['rounding'] ?? 'none';
        $applies = $mode !== 'none' && (! ($tax['cash_only'] ?? true) || $paymentMethod === 'tunai');
        $unit = max(1, (int) ($tax['rounding_unit'] ?? 100));
        $payable = ! $applies ? $total : match ($mode) {
            'up' => (int) (ceil($total / $unit) * $unit),
            'down' => (int) (floor($total / $unit) * $unit),
            default => (int) (round($total / $unit) * $unit),
        };

        return [
            'subtotal' => $subtotal,
            'tax' => $added,
            'tax_included' => $inside,
            'total' => $total,
            'rounding' => $payable - $total,
            'payable' => $payable,
        ];
    }
}
