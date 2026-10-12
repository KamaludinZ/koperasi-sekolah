<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /masuk {email, password, remember?}
 * Masuk petugas. JSON → 200 {data: petugas, redirect} atau 422;
 * form biasa → redirect ke halaman tujuan/halaman awal peran.
 */
class LoginController extends Controller
{
    public function __invoke(Request $request, AuthService $auth): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'max:100'],
            'remember' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'Masukkan email akun Anda.',
            'email.email' => 'Format email belum benar.',
            'password.required' => 'Masukkan kata sandi.',
        ]);

        $user = $auth->login($data['email'], $data['password'], (bool) ($data['remember'] ?? false), $request);
        $home = route(Menu::homeRoute($user->roleName()));

        if ($request->expectsJson()) {
            return response()->json(['data' => AuthService::toArray($user), 'redirect' => redirect()->intended($home)->getTargetUrl()]);
        }

        return redirect()->intended($home);
    }
}
