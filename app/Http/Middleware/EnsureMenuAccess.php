<?php

namespace App\Http\Middleware;

use App\Support\Petugas;
use App\Support\Menu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware rute `menu` / `menu:<kunci>`:
 * - wajib masuk: belum masuk → /masuk (lalu kembali ke tujuan), JSON → 401;
 * - dengan kunci menu: peran harus boleh membuka menu itu (roles.permissions) → bila tidak, 403
 *   dengan pesan siapa yang boleh membukanya.
 * Didaftarkan juga sebagai persistent middleware Livewire, jadi aksi komponen ikut dicek.
 */
class EnsureMenuAccess
{
    public function handle(Request $request, Closure $next, ?string $menu = null): Response
    {
        $user = Petugas::current();

        if (! $user) {
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(401, 'Silakan masuk terlebih dahulu.');
            }

            return redirect()->guest(route('login'))->with('status', 'Silakan masuk dulu untuk membuka halaman itu.');
        }

        if ($menu !== null && ! Menu::userCan($user, $menu)) {
            abort(403, Menu::deniedMessage($menu));
        }

        return $next($request);
    }
}
