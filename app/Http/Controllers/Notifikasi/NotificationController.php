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
 * - POST /notifikasi/tandai        {ids: [..], dibaca: true|false}           → tandai dibaca / belum dibaca
 * - POST /notifikasi/tandai-semua  {tampil?: semua|stok|pengumuman|sistem}   → tandai semua dibaca
 * - POST /notifikasi/sembunyikan   {ids: [..]}                               → sembunyikan dari kotak sendiri
 * Hanya notifikasi di kotak petugas sendiri yang terpengaruh; id lain diabaikan.
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

    public function mark(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'dibaca' => ['sometimes', 'boolean'],
        ], ['ids.required' => 'Pilih notifikasi yang akan ditandai.']);
        $user = $request->user();
        $ids = $notifications->mark($user, $data['ids'], $data['dibaca'] ?? true);

        return response()->json(['data' => ['ids' => $ids, 'read' => $data['dibaca'] ?? true], 'meta' => ['unread' => $notifications->unreadCount($user)]]);
    }

    public function markAll(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['tampil' => ['nullable', Rule::in(['semua', 'stok', 'pengumuman', 'sistem'])]]);
        $user = $request->user();
        $ids = $notifications->markAll($user, $data['tampil'] ?? 'semua');

        return response()->json(['data' => ['ids' => $ids, 'read' => true], 'meta' => ['unread' => $notifications->unreadCount($user)]]);
    }

    public function dismiss(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $user = $request->user();

        return response()->json(['data' => ['ids' => $notifications->dismiss($user, $data['ids'])], 'meta' => ['unread' => $notifications->unreadCount($user)]]);
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
