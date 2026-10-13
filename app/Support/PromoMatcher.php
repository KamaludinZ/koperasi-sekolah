<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Mencocokkan isi keranjang dengan promo otomatis yang sedang berlaku (status aktif, hari &
 * jam berlaku, cakupan semua/kategori/barang, minimal belanja). Satu baris barang hanya
 * mendapat satu promo — yang paling hemat (promo tidak bertumpuk).
 * Hasil juga memuat petunjuk agar pembeli dapat promo (mis. "tambah 1 lagi, gratis 1").
 */
class PromoMatcher
{
    /**
     * @param  list<array{id: int, name: string, category: ?string, price: int, quantity: int, subtotal: int}>  $lines
     * @param  list<array>  $promos  lihat PromoEngine::toArray()
     * @return array{applied: list<array{id: int, name: string, rule: string, items: list<string>, saving: int}>, hints: list<array{id: int, name: string, message: string}>, total: int}
     */
    public static function match(array $lines, array $promos, ?Carbon $now = null): array
    {
        $now ??= now();
        $live = array_filter($promos, fn (array $p) => self::isLive($p, $now));

        // Hemat per baris per promo, lalu pilih promo terbaik tiap baris.
        $best = [];
        $hints = [];
        foreach ($live as $promo) {
            $eligible = array_filter($lines, fn (array $l) => self::covers($promo, $l));
            if ($eligible === []) {
                continue;
            }
            $eligibleTotal = array_sum(array_column($eligible, 'subtotal'));
            if ($promo['min_purchase'] > 0 && $eligibleTotal < $promo['min_purchase']) {
                $hints[] = ['id' => $promo['id'], 'name' => $promo['name'], 'message' => 'Belanja Rp'.number_format($promo['min_purchase'] - $eligibleTotal, 0, ',', '.').' lagi untuk dapat promo ini.'];

                continue;
            }
            foreach ($eligible as $line) {
                $saving = self::saving($promo, $line);
                if ($saving > 0 && $saving > ($best[$line['id']]['saving'] ?? 0)) {
                    $best[$line['id']] = ['promo' => $promo, 'line' => $line, 'saving' => $saving];
                }
                if ($saving === 0 && ($hint = self::hint($promo, $line))) {
                    $hints[] = ['id' => $promo['id'], 'name' => $promo['name'], 'message' => $hint];
                }
            }
        }

        $applied = [];
        foreach ($best as $b) {
            $id = $b['promo']['id'];
            $applied[$id] ??= ['id' => $id, 'name' => $b['promo']['name'], 'rule' => \App\Livewire\Promo\Index::describe($b['promo']), 'items' => [], 'saving' => 0];
            $applied[$id]['items'][] = $b['line']['name'];
            $applied[$id]['saving'] += $b['saving'];
        }
        // Petunjuk promo yang sudah dipakai baris lain tetap ditampilkan; yang ganda dibuang.
        $hints = array_values(array_unique($hints, SORT_REGULAR));

        return ['applied' => array_values($applied), 'hints' => $hints, 'total' => array_sum(array_column($applied, 'saving'))];
    }

    /** Promo aktif yang berlaku pada hari & jam ini. */
    public static function isLive(array $promo, Carbon $now): bool
    {
        if (($promo['status'] ?? null) !== 'aktif' || ! in_array($now->dayOfWeekIso, $promo['days'], true)) {
            return false;
        }
        if ($promo['time_from'] && $promo['time_to']) {
            $time = $now->format('H:i');

            return $time >= $promo['time_from'] && $time <= $promo['time_to'];
        }

        return true;
    }

    private static function covers(array $promo, array $line): bool
    {
        return match ($promo['scope']) {
            'kategori' => in_array($line['category'] ?? null, $promo['targets'], true),
            // Barang dicocokkan lewat id (data database); data tanpa id dicocokkan lewat nama.
            'barang' => isset($promo['product_ids']) ? in_array($line['id'], $promo['product_ids'], true) : in_array($line['name'], $promo['targets'], true),
            default => true,
        };
    }

    /** Rupiah hemat satu baris barang dari satu promo (0 bila syarat belum terpenuhi). */
    private static function saving(array $promo, array $line): int
    {
        $qty = $line['quantity'];
        if ($qty < max(1, $promo['min_qty'])) {
            return 0;
        }

        $saving = match ($promo['type']) {
            'persen' => (int) round($line['subtotal'] * $promo['value'] / 100),
            'nominal' => $promo['value'] * $qty,
            'beli_gratis' => intdiv($qty, $promo['buy_qty'] + $promo['free_qty']) * $promo['free_qty'] * $line['price'],
            'grosir' => max(0, $line['price'] - $promo['value']) * $qty,
            default => 0,
        };

        return min($saving, $line['subtotal']);
    }

    private static function hint(array $promo, array $line): ?string
    {
        $qty = $line['quantity'];

        return match ($promo['type']) {
            'beli_gratis' => ($need = ($promo['buy_qty'] + $promo['free_qty']) - $qty % ($promo['buy_qty'] + $promo['free_qty'])) <= $promo['free_qty']
                ? "Tambah {$need} {$line['name']} lagi — gratis {$promo['free_qty']}."
                : "Beli {$promo['buy_qty']} {$line['name']} gratis {$promo['free_qty']}.",
            // Petunjuk grosir hanya bila sudah setengah jalan, agar tidak mengganggu pembelian biasa.
            'grosir' => $qty * 2 >= $promo['min_qty']
                ? 'Tambah '.($promo['min_qty'] - $qty)." {$line['name']} lagi untuk harga grosir Rp".number_format($promo['value'], 0, ',', '.').'/pcs.'
                : null,
            default => null,
        };
    }
}
