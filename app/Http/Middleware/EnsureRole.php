<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Support\Petugas;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware rute peran — memeriksa SELURUH peran yang dipegang akun (multi-peran), bukan hanya peran utama:
 * - `peran:admin,pengurus`       → lewat bila akun memegang SALAH SATU peran yang disebut;
 * - `peran:semua,admin,pengurus` → lewat hanya bila akun memegang SEMUA peran yang disebut.
 * Akun nonaktif selalu ditolak. Nama peran yang tidak dikenal adalah salah tulis di rute: di lokal/pengujian
 * dilempar sebagai LogicException agar segera ketahuan, di produksi rute ditolak (403) dan dicatat ke log.
 * Logika pencocokan (allows/parse) dipakai juga oleh Menu::canReach, Menu::denial & simulasi hak akses.
 */
class EnsureRole
{
    public const ALL = 'semua';

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        $user = Petugas::current();

        if (! $user) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }
        ['roles' => $roles, 'all' => $all, 'unknown' => $unknown] = self::parse($args);
        if ($unknown !== []) {
            $msg = 'Rute '.($request->route()?->getName() ?? $request->path()).' memakai peran tak dikenal: '.implode(', ', $unknown);
            if (app()->environment('local', 'testing')) {
                throw new \LogicException($msg);
            }
            Log::error($msg);
            abort(403, 'Halaman ini sedang tidak dapat dibuka. Hubungi admin koperasi.');
        }
        if (($user['is_active'] ?? true) === false) {
            abort(403, 'Akun Anda nonaktif.');
        }
        if (! self::allows($user['roles'] ?? array_filter([$user['role'] ?? null]), $args)) {
            abort(403, self::message($roles, $all));
        }

        return $next($request);
    }

    /**
     * Uraikan parameter middleware: daftar peran, mode "semua", dan nama yang tidak dikenal.
     *
     * @param  list<string>  $args
     * @return array{roles: list<string>, all: bool, unknown: list<string>}
     */
    public static function parse(array $args): array
    {
        $args = array_values(array_filter(array_map('trim', $args), 'strlen'));
        $all = ($args[0] ?? null) === self::ALL;
        $roles = array_values(array_unique($all ? array_slice($args, 1) : $args));

        return ['roles' => $roles, 'all' => $all, 'unknown' => array_values(array_diff($roles, array_keys(Role::LABELS)))];
    }

    /**
     * Peran-peran yang dipegang memenuhi syarat rute? Tanpa syarat peran = boleh.
     *
     * @param  list<string>  $held  semua peran yang dipegang akun
     * @param  list<string>  $args  parameter middleware (boleh diawali `semua`)
     */
    public static function allows(array $held, array $args): bool
    {
        ['roles' => $roles, 'all' => $all, 'unknown' => $unknown] = self::parse($args);
        if ($roles === []) {
            return true;
        }
        if ($unknown !== []) {
            return false;
        }

        return $all ? array_diff($roles, $held) === [] : array_intersect($held, $roles) !== [];
    }

    /** Pesan 403, mis. "Halaman ini hanya untuk peran Admin dan Pengurus." / "… untuk akun yang memegang peran Admin sekaligus Pengurus." */
    public static function message(array $roles, bool $all = false): string
    {
        $labels = collect($roles)->map(fn ($r) => Role::LABELS[$r] ?? $r);
        if ($all && $labels->count() > 1) {
            return 'Halaman ini hanya untuk akun yang memegang peran '.$labels->slice(0, -1)->join(', ').' sekaligus '.$labels->last().'.';
        }

        return 'Halaman ini hanya untuk peran '.($labels->count() > 1 ? $labels->slice(0, -1)->join(', ').' dan '.$labels->last() : $labels->first()).'.';
    }
}
