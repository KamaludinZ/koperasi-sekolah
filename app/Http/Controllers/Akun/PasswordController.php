<?php

namespace App\Http\Controllers\Akun;

use App\Http\Controllers\Controller;
use App\Services\PasswordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PUT /akun/kata-sandi {current_password, password, password_confirmation}
 * Ganti kata sandi akun yang sedang masuk (semua peran). 200 {message, other_sessions_ended} / 422.
 */
class PasswordController extends Controller
{
    public function __invoke(Request $request, PasswordService $passwords): JsonResponse
    {
        $revoked = $passwords->change($request->user(), $request->only('current_password', 'password', 'password_confirmation'));

        return response()->json([
            'message' => 'Kata sandi berhasil diganti.',
            'other_sessions_ended' => $revoked,
        ]);
    }
}
