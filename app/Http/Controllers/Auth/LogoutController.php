<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLog;
use App\Support\MockData;
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
        if ($user = MockData::currentUser()) {
            AuditLog::record('auth.logout', 'auth', "{$user['name']} keluar");
        }
        $request->session()->forget('mock.user');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Anda sudah keluar. Sampai jumpa lagi.');
    }
}
