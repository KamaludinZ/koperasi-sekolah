<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catat permintaan halaman yang lambat (> THRESHOLD_MS) per nama route selama 7 hari: jumlah, waktu
 * terlama & rata-rata, terakhir terjadi. Ditampilkan di Sistem → Performa.
 */
class TrackSlowRequests
{
    public const THRESHOLD_MS = 1000;

    public const KEY = 'perf:slow';

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($ms > self::THRESHOLD_MS && ($route = $request->route()?->getName())) {
            try {
                $all = Cache::get(self::KEY, []);
                $r = $all[$route] ?? ['route' => $route, 'path' => '/'.ltrim($request->path(), '/'), 'count' => 0, 'max_ms' => 0, 'total_ms' => 0];
                $r['count']++;
                $r['max_ms'] = max($r['max_ms'], $ms);
                $r['total_ms'] += $ms;
                $r['last_at'] = now()->toDateTimeString();
                $all[$route] = $r;
                Cache::put(self::KEY, $all, now()->addDays(7));
            } catch (\Throwable) {
                // pencatatan tidak boleh mengganggu respons
            }
        }

        return $response;
    }

    /** @return list<array{route: string, path: string, count: int, max_ms: int, avg_ms: int, last_at: string}> terlambat dulu */
    public static function report(): array
    {
        return collect(Cache::get(self::KEY, []))->map(fn ($r) => [...$r, 'avg_ms' => (int) round($r['total_ms'] / max(1, $r['count']))])
            ->sortByDesc('max_ms')->values()->all();
    }
}
