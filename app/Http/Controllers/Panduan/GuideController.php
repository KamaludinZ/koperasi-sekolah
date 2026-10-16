<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Services\GuideService;
use App\Support\Petugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API Pusat Panduan (isi efektif: otomatis + suntingan admin), disaring sesuai izin pengguna:
 * - GET /panduan/data?q=&terbatas=1  → panduan petugas yang boleh dibuka (gabungan izin semua peran); terbatas=1 ikut
 *   menyertakan menu lain bertanda restricted tanpa langkah;
 * - GET /panduan/data/{key}          → satu panduan; menu terbatas → 403 dengan peran yang dibutuhkan;
 * - GET /portal/panduan/data         → panduan anggota (login portal).
 */
class GuideController extends Controller
{
    public function index(Request $request, GuideService $guides): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'terbatas' => ['nullable', 'boolean']]);
        $user = Petugas::current();
        $data = $guides->listFor($user, 'petugas', (bool) ($f['terbatas'] ?? false), (string) ($f['q'] ?? ''));

        return response()->json(['data' => $data, 'meta' => [
            'total' => count($data),
            'restricted' => count(array_filter($data, fn ($g) => $g['restricted'])),
            'hidden_restricted' => ($f['terbatas'] ?? false) ? 0 : count($guides->listFor($user, 'petugas', true, (string) ($f['q'] ?? ''))) - count($data),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function show(string $key, GuideService $guides): JsonResponse
    {
        $all = $guides->all('petugas');
        abort_unless(isset($all[$key]), 404, 'Panduan tidak ditemukan.');
        $user = Petugas::current();
        $g = $guides->present($key, $all[$key], $guides->allowed($user, $all[$key]));
        if ($g['restricted']) {
            return response()->json(['message' => 'Menu '.$g['menu_label'].' tidak termasuk peran Anda.', 'data' => $g], 403);
        }

        return response()->json(['data' => $g])->header('Cache-Control', 'private, no-store');
    }

    public function portal(Request $request, GuideService $guides): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:60']]);

        return response()->json(['data' => $guides->listFor(null, 'anggota', false, (string) ($f['q'] ?? ''))])->header('Cache-Control', 'private, no-store');
    }
}
