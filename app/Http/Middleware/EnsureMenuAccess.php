<?php

namespace App\Http\Middleware;

use App\Support\Menu;
use App\Support\MockData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tolak (403) halaman & endpoint menu yang tidak diizinkan untuk peran petugas.
 * Menu ditentukan dari segmen URL pertama (kasir, barang, transaksi, anggota, laporan, pengaturan).
 * Tahap frontend: peran dari akun tiruan di session; belum masuk = mode demo (semua terbuka).
 */
class EnsureMenuAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $menu = $request->segment(1);
        $user = MockData::currentUser();

        if ($user && $menu && array_key_exists($menu, Menu::ITEMS) && ! Menu::allows($user['role'], $menu)) {
            abort(403, 'Menu ini tidak tersedia untuk peran Anda.');
        }

        return $next($request);
    }
}
