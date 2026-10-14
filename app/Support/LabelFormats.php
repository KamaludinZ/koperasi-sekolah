<?php

namespace App\Support;

use App\Models\Product;

/**
 * Format kertas label barcode (ukuran dalam mm) dan penyusunan label per lembar.
 * Dipakai halaman Cetak label (pratinjau) dan tampilan siap cetak.
 */
class LabelFormats
{
    public const MAX_LABELS = 1000;

    public const MAX_COPIES = 200;

    /** key → [label, lebar kertas, tinggi kertas, kolom, baris, lebar label, tinggi label, margin atas, margin kiri, tinggi barcode] */
    public const FORMATS = [
        'a4-3x8' => ['A4 · 24 label (3×8, 70×37 mm)', 210, 297, 3, 8, 70, 37, 0.5, 0, 14],
        'a4-4x10' => ['A4 · 40 label (4×10, 52,5×29,7 mm)', 210, 297, 4, 10, 52.5, 29.7, 0, 0, 10],
        'thermal-50x30' => ['Printer label · 50×30 mm', 50, 30, 1, 1, 50, 30, 0, 0, 11],
        'thermal-58' => ['Printer struk 58 mm · 58×30 mm', 58, 30, 1, 1, 58, 30, 0, 0, 11],
        'thermal-80' => ['Printer struk 80 mm · 2 label 40×30 mm', 80, 30, 2, 1, 40, 30, 0, 0, 11],
    ];

    /** Format thermal yang cocok dengan lebar kertas struk di Pengaturan (58/80 mm). */
    public static function thermalForReceipt(): string
    {
        $paper = (string) (app(\App\Services\SettingsService::class)->section('receipt')['paper'] ?? '58mm');

        return str_starts_with($paper, '80') ? 'thermal-80' : 'thermal-58';
    }

    public const DEFAULT = 'a4-3x8';

    /** @return array{key: string, label: string, page_w: float, page_h: float, cols: int, rows: int, label_w: float, label_h: float, top: float, left: float, barcode_h: float, per_page: int} */
    public static function get(string $key): array
    {
        $key = array_key_exists($key, self::FORMATS) ? $key : self::DEFAULT;
        [$label, $pw, $ph, $cols, $rows, $lw, $lh, $top, $left, $bh] = self::FORMATS[$key];

        return ['key' => $key, 'label' => $label, 'page_w' => $pw, 'page_h' => $ph, 'cols' => $cols, 'rows' => $rows,
            'label_w' => $lw, 'label_h' => $lh, 'top' => $top, 'left' => $left, 'barcode_h' => $bh, 'per_page' => $cols * $rows];
    }

    /** "12:3,15:1" → [12 => 3, 15 => 1] (salinan dibatasi 1–MAX_COPIES). */
    public static function parseItems(?string $raw): array
    {
        $out = [];
        foreach (explode(',', (string) $raw) as $part) {
            if (preg_match('/^\s*(\d+)(?::(\d+))?\s*$/', $part, $m)) {
                $out[(int) $m[1]] = max(1, min(self::MAX_COPIES, (int) ($m[2] ?? 1)));
            }
        }

        return $out;
    }

    /** [12 => 3] → "12:3" */
    public static function encodeItems(array $items): string
    {
        return collect($items)->map(fn ($copies, $id) => "{$id}:{$copies}")->implode(',');
    }

    /**
     * Label siap cetak (sesuai urutan barang & salinan), dibagi per lembar. Barang terhapus dilewati.
     *
     * @param  array<int, int>  $items  id barang → jumlah salinan
     * @return array{pages: list<list<array>>, total: int, truncated: bool}
     */
    public static function sheets(array $items, string $format): array
    {
        $f = self::get($format);
        $products = Product::whereKey(array_keys($items))->get()->keyBy('id');
        $labels = [];
        foreach ($items as $id => $copies) {
            if (! $p = $products->get($id)) {
                continue;
            }
            $label = ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'price' => (int) round((float) $p->price), 'barcode' => BarcodeImage::value($p)];
            for ($i = 0; $i < $copies && count($labels) < self::MAX_LABELS; $i++) {
                $labels[] = $label;
            }
        }
        $total = array_sum(array_map(fn ($id) => $products->has($id) ? $items[$id] : 0, array_keys($items)));

        return ['pages' => array_chunk($labels, $f['per_page']), 'total' => count($labels), 'truncated' => $total > self::MAX_LABELS];
    }
}
