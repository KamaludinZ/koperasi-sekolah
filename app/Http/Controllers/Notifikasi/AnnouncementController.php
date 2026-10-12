<?php

namespace App\Http\Controllers\Notifikasi;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengumuman untuk anggota & petugas (JSON, Admin & Pengurus):
 * - GET    /notifikasi/pengumuman/data         → semua pengumuman (aktif & lewat)
 * - POST   /notifikasi/pengumuman              → terbitkan {title, message, target, show_public, expires_at} (201)
 * - PUT    /notifikasi/pengumuman/{pengumuman} → ubah (Admin: semua, Pengurus: miliknya)
 * - DELETE /notifikasi/pengumuman/{pengumuman} → hapus (Admin: semua, Pengurus: miliknya)
 */
class AnnouncementController extends Controller
{
    public function index(AnnouncementService $announcements): JsonResponse
    {
        return response()->json([
            'data' => $announcements->list(),
            'meta' => ['targets' => AnnouncementService::TARGETS, 'public_targets' => AnnouncementService::PUBLIC_TARGETS],
        ]);
    }

    public function store(Request $request, AnnouncementService $announcements): JsonResponse
    {
        $data = $request->validate(AnnouncementService::rules(), AnnouncementService::messages());

        return response()->json(['data' => AnnouncementService::toArray($announcements->create($data, $request->user()))], 201);
    }

    public function update(Request $request, int $pengumuman, AnnouncementService $announcements): JsonResponse
    {
        $n = $this->find($pengumuman);
        $data = $request->validate(AnnouncementService::rules(), AnnouncementService::messages());

        return response()->json(['data' => AnnouncementService::toArray($announcements->update($n, $data, $request->user()))]);
    }

    public function destroy(Request $request, int $pengumuman, AnnouncementService $announcements): JsonResponse
    {
        $announcements->delete($this->find($pengumuman), $request->user());

        return response()->json(['data' => ['id' => $pengumuman, 'deleted' => true]]);
    }

    private function find(int $id): Notification
    {
        return Notification::where('type', 'pengumuman')->whereNotNull('created_by')->findOrFail($id);
    }
}
