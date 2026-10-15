<?php

namespace App\Http\Controllers\Pengguna;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionMerger;
use App\Services\UserService;
use App\Support\AccessPreview;
use App\Support\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * - PUT  /pengguna/{user}/peran         → simpan seluruh peran {roles: [...] (min. 1), expected_roles?: [...]} — bila
 *        expected_roles dikirim dan peran tersimpan sudah berbeda (diubah admin lain) → 409 tanpa menyimpan
 * - POST /pengguna/{user}/peran/{role}  → tambah satu peran;  DELETE … → cabut satu peran (peran terakhir ditolak)
 *   Respons ketiganya: akun (format daftar) + added/removed + changed.
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
        $pivots = $u->roles->keyBy('name')->map(fn ($r) => $r->pivot);
        $granters = User::whereIn('id', $pivots->pluck('granted_by')->filter()->unique()->all())->pluck('name', 'id');

        return UserService::toArray($u) + [
            'roles_detail' => array_map(fn ($n) => [
                'name' => $n, 'label' => Role::LABELS[$n] ?? $n, 'primary' => $n === $u->role?->name,
                'granted_at' => ($at = $pivots->get($n)?->created_at) ? Carbon::parse($at)->toIso8601String() : null,
                'granted_by' => ($gid = $pivots->get($n)?->granted_by) ? ['id' => (int) $gid, 'name' => $granters[$gid] ?? null] : null,
            ], $names),
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

    public function saveRoles(Request $request, User $user, UserService $users): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['present', 'array', 'max:'.count(Role::LABELS)],
            'roles.*' => ['string', 'distinct', Rule::in(array_keys(Role::LABELS))],
            'expected_roles' => ['sometimes', 'array'],
            'expected_roles.*' => ['string'],
            'alasan' => ['nullable', 'string', 'max:200'],
        ], ['roles.present' => UserService::MSG_MIN_ROLE, 'roles.array' => UserService::MSG_MIN_ROLE, 'roles.*.in' => 'Peran tidak dikenal.', 'roles.*.distinct' => 'Peran tidak boleh dobel.']);
        $user->load('role', 'roles');
        if (array_key_exists('expected_roles', $data) && Role::sortNames($data['expected_roles']) !== $user->roleNames()) {
            return response()->json(['message' => 'Peran akun ini sudah diubah petugas lain. Muat ulang lalu ulangi.',
                'data' => self::row($user)], 409);
        }
        if ($data['roles'] === []) {
            return response()->json(['message' => UserService::MSG_MIN_ROLE, 'errors' => ['roles' => [UserService::MSG_MIN_ROLE]]], 422);
        }

        return $this->roleResult($users->updateRoles($user, $data['roles'], $request->user(), $data['alasan'] ?? null));
    }

    public function addRole(Request $request, User $user, string $role, UserService $users): JsonResponse
    {
        abort_unless(array_key_exists($role, Role::LABELS), 404, 'Peran tidak dikenal.');

        $reason = $request->validate(['alasan' => ['nullable', 'string', 'max:200']])['alasan'] ?? null;

        return $this->roleResult($users->addRole($user->load('role', 'roles'), $role, $request->user(), $reason));
    }

    public function removeRole(Request $request, User $user, string $role, UserService $users): JsonResponse
    {
        abort_unless(array_key_exists($role, Role::LABELS), 404, 'Peran tidak dikenal.');

        $reason = $request->validate(['alasan' => ['nullable', 'string', 'max:200']])['alasan'] ?? null;

        return $this->roleResult($users->removeRole($user->load('role', 'roles'), $role, $request->user(), $reason));
    }

    private function roleResult(array $r): JsonResponse
    {
        $changed = $r['added'] !== [] || $r['removed'] !== [];

        return response()->json(['data' => self::row($r['user']->fresh(['role', 'roles'])), 'added' => $r['added'], 'removed' => $r['removed'], 'changed' => $changed,
            'message' => $changed ? 'Peran disimpan.' : 'Peran tidak berubah.']);
    }

    /** Hak akses efektif akun: gabungan izin semua perannya beserta asal peran, halaman awal & hak aksi. */
    public function permissions(User $user, PermissionMerger $merger): JsonResponse
    {
        return response()->json(['data' => ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]] + $merger->effective($user)]);
    }

    /**
     * Simulasi kombinasi peran tiruan (tanpa akun, tanpa menyimpan): ?peran=pengurus,kasir atau peran[]=…
     * Peran yang tidak dikenal ditolak 422 agar salah ketik tidak diam-diam diabaikan.
     */
    public function simulate(Request $request): JsonResponse
    {
        $raw = $request->query('peran', []);
        $names = is_string($raw) ? array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen')) : $raw;
        $data = validator(['peran' => $names], [
            'peran' => ['required', 'array', 'min:1', 'max:'.count(Role::LABELS)],
            'peran.*' => ['string', 'distinct', Rule::in(Role::query()->pluck('name')->all())],
        ], [
            'peran.required' => UserService::MSG_MIN_ROLE, 'peran.min' => UserService::MSG_MIN_ROLE,
            'peran.*.in' => 'Peran :input tidak dikenal.', 'peran.*.distinct' => 'Peran tidak boleh berulang.',
        ])->validate();

        return response()->json(['data' => (new AccessPreview)->simulate($data['peran'])]);
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
            'alasan' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['data' => $users->updatePermissions($role, $data['permissions'], $data['alasan'] ?? null)->only('id', 'name', 'label', 'permissions')]);
    }
}
