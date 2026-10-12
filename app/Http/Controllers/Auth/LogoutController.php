<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /keluar — akhiri sesi petugas lalu kembali ke halaman masuk.
 * Tahap frontend: menghapus akun tiruan dari session.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->forget('mock.user');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Anda sudah keluar. Sampai jumpa lagi.');
    }
}
