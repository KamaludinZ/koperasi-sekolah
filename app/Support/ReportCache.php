<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache hasil laporan penjualan. Kunci memuat versi data penjualan yang dinaikkan setiap kali transaksi,
 * retur, atau pembatalan tersimpan (setelah commit), sehingga laporan tidak pernah menampilkan data basi;
 * selebihnya berlaku TTL. Hitungan kena/meleset per laporan disimpan untuk halaman Sistem → Performa.
 */
class ReportCache
{
    public const VERSION_KEY = 'laporan:versi';

    public const TTL = 600;

    /** Laporan yang dicache (nama → label tampilan). */
    public const LABELS = [
        'summary' => 'Ringkasan penjualan', 'top' => 'Barang terlaris', 'daily' => 'Rekap per hari',
        'chart' => 'Grafik penjualan', 'today' => 'Ringkasan hari ini',
    ];

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /** Naikkan versi setelah transaksi DB selesai (langsung bila tidak sedang dalam transaksi). */
    public static function bump(): void
    {
        DB::afterCommit(function () {
            Cache::add(self::VERSION_KEY, 1);
            Cache::increment(self::VERSION_KEY);
        });
    }

    public static function remember(string $name, array $args, Closure $compute, ?int $ttl = null): mixed
    {
        $key = 'laporan:'.$name.':v'.self::version().':'.md5(json_encode(array_map(
            fn ($a) => $a instanceof DateTimeInterface ? $a->format('Y-m-d H:i') : $a, $args)));
        $hit = true;
        $value = Cache::remember($key, $ttl ?? self::TTL, function () use ($compute, &$hit) {
            $hit = false;

            return $compute();
        });
        Cache::increment("laporan:stat:{$name}:".($hit ? 'hits' : 'misses'));
        if (! $hit) {
            Cache::put("laporan:stat:{$name}:at", now()->toDateTimeString(), now()->addDay());
        }

        return $value;
    }

    /** @return array<string, array{hits: int, misses: int, at: ?string}> */
    public static function stats(): array
    {
        return collect(self::LABELS)->mapWithKeys(fn ($l, $n) => [$n => [
            'hits' => (int) Cache::get("laporan:stat:{$n}:hits", 0),
            'misses' => (int) Cache::get("laporan:stat:{$n}:misses", 0),
            'at' => Cache::get("laporan:stat:{$n}:at"),
        ]])->all();
    }
}
