<?php

namespace App\Http\Middleware;

use App\Support\Menu;
use App\Support\MockData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga rute halaman petugas, ditentukan dari segmen URL pertama:
 * - menu (kasir, barang, transaksi, anggota, laporan, pengguna, pengaturan) & `akun` wajib masuk;
 *   belum masuk → ke /masuk (lalu kembali ke tujuan), atau 401 untuk permintaan JSON;
 * - menu di luar hak peran → 403 dengan pesan siapa yang boleh membukanya.
 * Beranda, /masuk, aset, dan /livewire tidak dijaga.
 * Tahap frontend: akun & peran dari session tiruan.
 */
class EnsureMenuAccess
{
    /** Segmen yang wajib masuk tetapi bukan menu (bisa dibuka semua peran). */
    public const ACCOUNT_SEGMENTS = ['akun'];

    public function handle(Request $request, Closure $next): Response
    {
        $segment = $request->segment(1);
        $isMenu = $segment && array_key_exists($segment, Menu::ITEMS);

        if (! $isMenu && ! in_array($segment, self::ACCOUNT_SEGMENTS, true)) {
            return $next($request);
        }

        $user = MockData::currentUser();

        if (! $user) {
            if ($request->expectsJson()) {
                abort(401, 'Silakan masuk terlebih dahulu.');
            }

            return redirect()->guest(route('login'))->with('status', 'Silakan masuk dulu untuk membuka halaman itu.');
        }

        if ($isMenu && ! Menu::allows($user['role'], $segment)) {
            abort(403, Menu::deniedMessage($segment));
        }

        return $next($request);
    }
}
