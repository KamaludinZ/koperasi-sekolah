<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\PortalPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PIN transaksi portal anggota (JSON, wajib masuk portal):
 * - GET  /portal/pin/status            → sudah punya PIN?, sisa percobaan, status terkunci (detik)
 * - POST /portal/pin/verifikasi {pin}  → PIN benar: token sekali pakai 5 menit untuk mengirim transaksi;
 *                                        salah: 422 dengan sisa percobaan; 3x salah → terkunci 15 menit
 */
class PinController extends Controller
{
    public function status(PortalPinService $pins): JsonResponse
    {
        $member = Auth::guard('member')->user();

        return response()->json(['data' => ['has_pin' => $member->hasPin()] + $pins->attempts($member)]);
    }

    public function verify(Request $request, PortalPinService $pins): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'max:10']], ['pin.required' => 'Masukkan PIN.']);
        $member = Auth::guard('member')->user();
        try {
            $pins->verify($member, $data['pin']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->errors()['pin'][0], 'errors' => $e->errors(), 'meta' => $pins->attempts($member)], 422);
        }

        return response()->json(['data' => ['verified' => true] + $pins->issueToken($member)]);
    }
}
