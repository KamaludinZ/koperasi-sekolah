<?php

namespace App\Http\Controllers\Panduan;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\GuideMerger;
use App\Services\GuideService;
use App\Services\MemberGuideService;
use App\Support\Menu;
use App\Support\Petugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * API Pusat Panduan (isi efektif: otomatis + suntingan admin), disaring sesuai izin pengguna dan diurutkan seperti
 * menu samping:
 * - GET /panduan/data?q=&terbatas=1&peran=&sebagai=  → panduan petugas yang boleh dibuka (gabungan izin semua peran);
 *   terbatas=1 ikut menyertakan menu lain bertanda restricted tanpa langkah; peran= menyaring ke satu peran milik
 *   pengguna; sebagai=kasir,pengurus meninjau dengan izin tiruan (hanya pengelola panduan);
 * - GET /panduan/data/{key}?sebagai=  → satu panduan; menu terbatas → 403 dengan peran yang dibutuhkan;
 * - GET /portal/panduan/data?q=      → panduan anggota (sesi portal: kata sandi + PIN) beserta pemberitahuan sesi
 *   (PIN belum diatur/terkunci, angsuran menunggak, mode baca-saja) — lihat MemberGuideService;
 * - GET /portal/panduan/data/{key}   → satu panduan anggota (404 untuk kunci petugas).
 */
class GuideController extends Controller
{
    public function index(Request $request, GuideService $guides, GuideMerger $merger): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:60'], 'terbatas' => ['nullable', 'boolean'],
            'peran' => ['nullable', 'string', 'in:'.implode(',', array_keys(Role::LABELS))], 'sebagai' => ['nullable', 'string', 'max:100'],
        ]);
        $viewer = $this->viewer($request, $guides);
        $mine = $guides->rolesOf($viewer);
        $role = ($f['peran'] ?? null) && count($mine) > 1 && in_array($f['peran'], $mine, true) ? $f['peran'] : null;
        abort_if(($f['peran'] ?? null) && $role === null && count($mine) > 1, 422, 'Peran itu tidak dimiliki akun ini.');
        $q = (string) ($f['q'] ?? '');
        $restricted = (bool) ($f['terbatas'] ?? false);
        $data = $guides->listFor($viewer, 'petugas', $restricted, $q, $role);
        $merged = $merger->merge($viewer, $guides->all('petugas'));

        return response()->json(['data' => $data, 'meta' => [
            'total' => count($data),
            'restricted' => count(array_filter($data, fn ($g) => $g['restricted'])),
            'hidden_restricted' => $restricted ? 0 : count($guides->listFor($viewer, 'petugas', true, $q, $role)) - count($data),
            'roles' => $mine, 'role' => $role, 'role_counts' => array_map('count', $merged['by_role']), 'menus' => count($merged['sources']),
            'shared' => $merged['shared'], 'duplicates_merged' => $merged['duplicates'],
            'preview' => (bool) ($viewer['mock'] ?? false),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $key, GuideService $guides): JsonResponse
    {
        $all = $guides->all('petugas');
        abort_unless(isset($all[$key]), 404, 'Panduan tidak ditemukan.');
        $viewer = $this->viewer($request, $guides);
        $g = $guides->present($key, $all[$key], $guides->allowed($viewer, $all[$key]), $viewer, Menu::sourcesFor($viewer));
        if ($g['restricted']) {
            return response()->json(['message' => $g['restriction']['message'], 'reason' => $g['restriction']['reason'], 'data' => $g], 403);
        }

        return response()->json(['data' => $g])->header('Cache-Control', 'private, no-store');
    }

    public function portal(Request $request, MemberGuideService $memberGuides): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:60']]);

        return response()->json($memberGuides->listFor(Auth::guard('member')->user(), (string) ($f['q'] ?? '')))->header('Cache-Control', 'private, no-store');
    }

    public function portalShow(string $key, MemberGuideService $memberGuides): JsonResponse
    {
        $res = $memberGuides->find(Auth::guard('member')->user(), $key);
        abort_if($res === null, 404, 'Panduan tidak ditemukan.');

        return response()->json($res)->header('Cache-Control', 'private, no-store');
    }

    /** Pembaca: petugas yang masuk, atau izin tiruan ?sebagai= (hanya pengelola panduan; selain itu 403). */
    private function viewer(Request $request, GuideService $guides): array
    {
        $raw = $request->query('sebagai');
        if ($raw === null || $raw === '') {
            return $guides->viewer(Petugas::current());
        }
        abort_unless(Gate::forUser($request->user('web'))->allows('kelola-panduan'), 403, 'Pratinjau peran hanya untuk pengelola panduan.');
        $roles = $guides->previewRoles($raw);
        abort_if($roles === [], 422, 'Peran pratinjau tidak dikenal.');

        return $guides->viewer(Petugas::current(), $roles);
    }
}
