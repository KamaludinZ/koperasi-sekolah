<?php

namespace App\Http\Middleware;

use App\Support\Petugas;
use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware rute `peran:admin,pengurus` — lewat bila akun memegang SALAH SATU peran yang disebut (multi-peran)
 * (pembatas tambahan untuk halaman sensitif seperti kelola pengguna & audit).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Petugas::current();

        if (! $user) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }
        if (array_intersect($user['roles'] ?? [$user['role']], $roles) === []) { // cukup salah satu peran yang dipegang
            $labels = collect($roles)->map(fn ($r) => Role::LABELS[$r] ?? $r);
            abort(403, 'Halaman ini hanya untuk peran '.($labels->count() > 1 ? $labels->slice(0, -1)->join(', ').' dan '.$labels->last() : $labels->first()).'.');
        }

        return $next($request);
    }
}
