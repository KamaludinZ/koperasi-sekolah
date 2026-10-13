<?php

namespace App\Http\Controllers\Anggota;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\MemberCodeGenerator;
use App\Services\MemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Anggota koperasi (JSON):
 * - GET    /anggota/daftar?q=&jenis=&kelas=&nonaktif=&per_halaman=&page= → daftar berpaginasi + ringkasan
 * - GET    /anggota/cari?q=           → pencarian cepat anggota aktif (maks. 10)
 * - GET    /anggota/kode-baru?jenis=  → saran kode anggota berikutnya
 * - POST   /anggota                   → anggota baru (201); kode kosong = dibuat otomatis
 * - GET    /anggota/{member}/data     → satu anggota
 * - PUT    /anggota/{member}          → perbarui data (saldo tidak berubah)
 * - PATCH  /anggota/{member}/status  → aktifkan/nonaktifkan anggota (aktif=true|false); saldo & riwayat tetap
 * - DELETE /anggota/{member}          → hapus lunak anggota tanpa saldo (alasan= opsional)
 */
class MemberController extends Controller
{
    public function index(Request $request, MemberService $members): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'jenis' => ['nullable', Rule::in(array_keys(Member::TYPES))],
            'kelas' => ['nullable', 'string', 'max:20'],
            'nonaktif' => ['nullable', 'boolean'],
            'per_halaman' => ['nullable', 'integer', Rule::in(MemberService::PER_PAGE_OPTIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $members->paginate([
            'search' => $data['q'] ?? '',
            'type' => $data['jenis'] ?? '',
            'class' => $data['kelas'] ?? '',
            'include_inactive' => (bool) ($data['nonaktif'] ?? false),
        ], (int) ($data['per_halaman'] ?? MemberService::PER_PAGE_OPTIONS[0]))
            ->withQueryString();

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'stats' => $members->stats(),
                'classes' => $members->classes(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function search(Request $request, MemberService $members): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $members->search($data['q'] ?? '')]);
    }

    public function nextCode(Request $request, MemberCodeGenerator $codes): JsonResponse
    {
        $data = $request->validate(['jenis' => ['nullable', Rule::in(array_keys(Member::TYPES))]]);

        return response()->json(['data' => ['member_code' => $codes->next($data['jenis'] ?? 'siswa')]]);
    }

    public function store(Request $request, MemberService $members): JsonResponse
    {
        $data = $request->validate(MemberService::rules(), MemberService::messages());

        return response()->json(['data' => MemberService::toArray($members->create($data))], 201);
    }

    public function show(Member $member): JsonResponse
    {
        return response()->json(['data' => MemberService::toArray($member)]);
    }

    public function update(Request $request, Member $member, MemberService $members): JsonResponse
    {
        $data = $request->validate(MemberService::rules($member->id), MemberService::messages());

        return response()->json(['data' => MemberService::toArray($members->update($member, $data))]);
    }

    public function status(Request $request, Member $member, MemberService $members): JsonResponse
    {
        $data = $request->validate(['aktif' => ['required', 'boolean']], ['aktif.required' => 'Wajib diisi.', 'aktif.boolean' => 'Status tidak valid.']);
        $member = $members->setActive($member, (bool) $data['aktif']);

        return response()->json([
            'data' => MemberService::toArray($member),
            'message' => $member->is_active ? "{$member->name} diaktifkan kembali." : "{$member->name} dinonaktifkan. Saldo dan riwayatnya tetap tersimpan.",
        ]);
    }

    public function destroy(Request $request, Member $member, MemberService $members): JsonResponse
    {
        $data = $request->validate(['alasan' => ['nullable', 'string', 'max:200']], ['alasan.max' => 'Alasan maksimal 200 karakter.']);
        $members->delete($member, $data['alasan'] ?? null);

        return response()->json(['message' => "{$member->name} dihapus dari daftar anggota. Data dapat dipulihkan Admin selama "
            .Member::retentionDays().' hari.']);
    }
}
