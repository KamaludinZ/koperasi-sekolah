<?php

namespace App\Http\Controllers\Pengaturan;

use App\Http\Controllers\Controller;
use App\Services\LogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Logo koperasi:
 * - GET    /logo/{berkas}                  → tayangkan logo (publik, cache 1 tahun)
 * - POST   /pengaturan/data/profile/logo   → unggah logo (multipart `logo`, khusus admin)
 * - DELETE /pengaturan/data/profile/logo   → hapus logo (khusus admin)
 */
class LogoController extends Controller
{
    public function show(string $file): StreamedResponse
    {
        $disk = Storage::disk(LogoService::DISK);
        abort_unless($disk->exists(LogoService::path($file)), 404);

        return $disk->response(LogoService::path($file), $file, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function store(Request $request, LogoService $logos): JsonResponse
    {
        $request->validate(['logo' => LogoService::rules()], LogoService::messages());

        return $this->respond($logos->store($request->file('logo'), $request->user()), 201);
    }

    public function destroy(Request $request, LogoService $logos): JsonResponse
    {
        return $this->respond($logos->delete($request->user()));
    }

    private function respond(array $all, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => ['logo' => $all['profile']['logo'], 'url' => LogoService::url($all['profile']['logo'])],
            'meta' => ['updated_at' => $all['updated_at'], 'updated_by' => $all['updated_by']],
        ], $status);
    }
}
