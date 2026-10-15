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
 * - GET  /pengguna/daftar?q=&peran=&status=&multi= → akun petugas beserta SELURUH perannya (label, peran utama, izin
 *        gabungan, penanda multi-peran / tanpa peran) + ringkasan per peran. peran: admin|kasir|pengurus|tanpa;
 *        status: aktif|nonaktif; multi=1 → hanya akun multi-peran.
 * - GET  /pengguna/{user}/data          → satu akun beserta peran & izin gabungannya
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
            'peran' => ['nullable', Rule::in([...array_keys(Role::LABELS), 'tanpa'])],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
            'multi' => ['nullable', 'boolean'],
        ], ['peran.in' => 'Peran tidak dikenal.', 'status.in' => 'Status harus aktif atau nonaktif.']);

        $rows = $users->query(['search' => $data['q'] ?? '', 'role' => $data['peran'] ?? ''])
            ->when(($data['status'] ?? null) === 'aktif', fn ($q) => $q->where('is_active', true))
            ->when(($data['status'] ?? null) === 'nonaktif', fn ($q) => $q->where('is_active', false))
            ->get()->map(fn (User $u) => self::row($u))
            ->when(! empty($data['multi']), fn ($c) => $c->where('is_multi_role', true))->values();

        return response()->json([
            'data' => $rows,
            'meta' => ['stats' => $users->stats(), 'roles' => Role::LABELS, 'filters' => ['q' => $data['q'] ?? null, 'peran' => $data['peran'] ?? null,
                'status' => $data['status'] ?? null, 'multi' => (bool) ($data['multi'] ?? false)]],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => self::row($user->load('role', 'roles'))]);
    }

    /** Baris akun untuk JSON: data akun + seluruh peran berlabel, peran utama, izin gabungan & penanda. */
    public static function row(User $u): array
    {
        $names = $u->roleNames();

        return UserService::toArray($u) + [
            'roles_detail' => array_map(fn ($n) => ['name' => $n, 'label' => Role::LABELS[$n] ?? $n, 'primary' => $n === $u->role?->name], $names),
            'primary_role' => $u->role?->name,
            'is_multi_role' => count($names) > 1,
            'has_no_role' => $names === [],
        ];
    }

    public function store(Request $request, UserService $users): JsonResponse
    {
        $data = $request->validate(UserService::rules(), UserService::messages());

        return response()->json(['data' => self::row($users->create($data))], 201);
    }

    public function update(Request $request, User $user, UserService $users): JsonResponse
    {
        $data = $request->validate(UserService::rules($user->id), UserService::messages());

        return response()->json(['data' => self::row($users->update($user, $data, $request->user()))]);
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
