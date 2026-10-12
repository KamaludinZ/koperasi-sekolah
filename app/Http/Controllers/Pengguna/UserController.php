<?php

namespace App\Http\Controllers\Pengguna;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kelola pengguna & peran (JSON, khusus admin):
 * - GET  /pengguna/daftar?q=&peran=     → akun petugas + ringkasan
 * - POST /pengguna                      → akun baru (201)
 * - PUT  /pengguna/{user}               → ubah akun / peran / status; `password` diisi = atur ulang
 * - POST /pengguna/{user}/status        → aktifkan / nonaktifkan
 * - GET  /pengguna/peran                → peran & menu yang boleh dibuka
 * - PUT  /pengguna/peran/{role}         → ubah hak menu peran {permissions: [...]}
 */
class UserController extends Controller
{
    public function index(Request $request, UserService $users): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'peran' => ['nullable', Rule::exists('roles', 'name')],
        ]);

        return response()->json([
            'data' => $users->query(['search' => $data['q'] ?? '', 'role' => $data['peran'] ?? ''])->get()->map(fn (User $u) => UserService::toArray($u)),
            'meta' => ['stats' => $users->stats()],
        ]);
    }

    public function store(Request $request, UserService $users): JsonResponse
    {
        $data = $request->validate(UserService::rules(), UserService::messages());

        return response()->json(['data' => UserService::toArray($users->create($data))], 201);
    }

    public function update(Request $request, User $user, UserService $users): JsonResponse
    {
        $data = $request->validate(UserService::rules($user->id), UserService::messages());

        return response()->json(['data' => UserService::toArray($users->update($user, $data, $request->user()))]);
    }

    public function toggle(Request $request, User $user, UserService $users): JsonResponse
    {
        return response()->json(['data' => UserService::toArray($users->toggleActive($user, $request->user()))]);
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::orderBy('id')->get(['id', 'name', 'label', 'permissions']),
            'menus' => collect(Menu::ITEMS)->map(fn ($m) => ['label' => $m['label'], 'description' => $m['description']]),
        ]);
    }

    public function updateRole(Request $request, Role $role, UserService $users): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::in(array_keys(Menu::ITEMS))],
        ]);

        return response()->json(['data' => $users->updatePermissions($role, $data['permissions'])->only('id', 'name', 'label', 'permissions')]);
    }
}
