<?php

namespace App\Http\Controllers\Notifikasi;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notifikasi petugas yang sedang masuk (JSON):
 * - GET /notifikasi/data?tampil=semua|belum|stok|pengumuman|sistem&batas=  → daftar + jumlah per saringan
 * - GET /notifikasi/terbaru?batas=5                                         → pratinjau lonceng + jumlah belum dibaca
 */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'tampil' => ['nullable', Rule::in(NotificationService::FILTERS)],
            'batas' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $user = $request->user();

        return response()->json([
            'data' => $notifications->list($user, $data['tampil'] ?? 'semua', (int) ($data['batas'] ?? 100)),
            'meta' => ['counts' => $notifications->counts($user), 'unread' => $notifications->unreadCount($user)],
        ]);
    }

    public function latest(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['batas' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $user = $request->user();

        return response()->json([
            'data' => $notifications->list($user, 'semua', (int) ($data['batas'] ?? 5)),
            'meta' => ['unread' => $notifications->unreadCount($user)],
        ]);
    }
}
