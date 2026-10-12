<?php

namespace App\Http\Middleware;

use App\Support\Petugas;
use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware rute `peran:admin,pengurus` — hanya peran yang disebut yang boleh lewat
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
        if (! in_array($user['role'], $roles, true)) {
            $labels = collect($roles)->map(fn ($r) => Role::LABELS[$r] ?? $r);
            abort(403, 'Halaman ini hanya untuk peran '.($labels->count() > 1 ? $labels->slice(0, -1)->join(', ').' dan '.$labels->last() : $labels->first()).'.');
        }

        return $next($request);
    }
}
