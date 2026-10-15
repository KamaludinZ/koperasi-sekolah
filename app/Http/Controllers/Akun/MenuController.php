<?php

namespace App\Http\Controllers\Akun;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuthService;
use App\Services\PermissionMerger;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /akun/menu — menu gabungan semua peran milik petugas yang sedang masuk (kontrak data menu samping):
 * kelompok → item (kunci, label, tautan, deskripsi, ikon, peran asal, penanda "hanya dari sebagian peran"),
 * peran yang dipegang (utama ditandai), halaman awal, dan hak aksi. `version` = sidik isi menu supaya klien
 * cukup menggambar ulang bila berubah (mis. setelah admin mencabut/menambah peran); dukung If-None-Match → 304.
 * Data pribadi per akun: tidak boleh di-cache bersama (Cache-Control: private, no-store).
 */
class MenuController extends Controller
{
    public function __invoke(Request $request, PermissionMerger $merger): JsonResponse
    {
        $user = $request->user()->loadMissing('role', 'roles');
        $access = $merger->effective($user);
        $multi = count($access['roles']) > 1;

        $groups = [];
        foreach ($access['menus'] as $m) {
            $item = Menu::ITEMS[$m['key']];
            $groups[$item['group']] ??= ['key' => $item['group'], 'label' => Menu::GROUPS[$item['group']], 'items' => []];
            $groups[$item['group']]['items'][] = [
                'key' => $m['key'],
                'label' => $item['label'],
                'description' => $item['description'],
                'icon' => $item['icon'],
                'url' => $item['route'] ? route($item['route'], absolute: false) : null,
                'from' => $m['from'],
                'partial' => $m['partial'],
            ];
        }
        // urutan kelompok & item mengikuti menu samping (Menu::GROUPS / Menu::ITEMS), bukan urutan izin
        $order = array_flip(array_keys(Menu::ITEMS));
        $groups = collect(Menu::GROUPS)->keys()->filter(fn ($g) => isset($groups[$g]))->map(function ($g) use ($groups, $order) {
            $items = $groups[$g]['items'];
            usort($items, fn ($a, $b) => $order[$a['key']] <=> $order[$b['key']]);

            return [...$groups[$g], 'items' => $items];
        })->values()->all();

        $data = [
            'user' => ['id' => $user->id, 'name' => $user->name],
            'roles' => array_map(fn ($r) => ['name' => $r, 'label' => Role::LABELS[$r] ?? $r, 'primary' => $r === $access['primary']], $access['roles']),
            'multi_role' => $multi,
            'groups' => $groups,
            'permissions' => $access['permissions'],
            'home' => ['route' => $access['home'], 'url' => route($access['home'], absolute: false)],
            'abilities' => collect($access['abilities'])->map(fn ($a) => ['allowed' => $a['allowed'], 'combined' => $a['combined']])->all(),
        ];
        $version = Menu::versionFor(AuthService::toArray($user));
        $etag = '"menu-'.$version.'"';

        $headers = ['Cache-Control' => 'private, no-store', 'ETag' => $etag, 'Vary' => 'Cookie'];
        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response()->json(null, 304, $headers);
        }

        return response()->json(['data' => $data, 'meta' => ['version' => $version]], 200, $headers);
    }
}
