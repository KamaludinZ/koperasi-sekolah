<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /keluar — akhiri sesi petugas (dicatat di audit) lalu kembali ke halaman masuk.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, AuthService $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('login')->with('status', 'Anda sudah keluar. Sampai jumpa lagi.');
    }
}
