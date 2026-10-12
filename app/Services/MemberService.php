<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Data anggota: aturan validasi bersama, tambah/ubah/hapus, pencarian & daftar
 * berpaginasi, ringkasan, dan bentuk array untuk tampilan/JSON.
 * Saldo tabungan tidak diubah di sini (lihat layanan tabungan).
 */
class MemberService
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public function __construct(private MemberCodeGenerator $codes) {}

    /** Aturan validasi bersama untuk form Livewire & endpoint JSON. Kode boleh kosong saat menambah (dibuat otomatis). */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'member_code' => [$ignoreId ? 'required' : 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+$/', function ($attr, $value, $fail) use ($ignoreId) {
                $taken = Member::where('member_code', mb_strtoupper(trim((string) $value)))
                    ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                    ->exists();
                if ($taken) {
                    $fail('Kode anggota sudah dipakai.');
                }
            }],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(Member::TYPES))],
            'class' => ['nullable', 'required_if:type,siswa', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]{8,20}$/'],
            'is_active' => ['boolean'],
        ];
    }

    public static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'class.required_if' => 'Kelas wajib diisi untuk siswa.',
            'member_code.regex' => 'Hanya huruf, angka, dan tanda -.',
            'phone.regex' => 'Nomor HP tidak valid.',
            'type.in' => 'Jenis anggota tidak dikenal.',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    /** @param  array<string, mixed>  $data  data tervalidasi */
    public function create(array $data): Member
    {
        return $this->codes->create([...$this->normalize($data), 'joined_at' => now()->toDateString()]);
    }

    public function update(Member $member, array $data): Member
    {
        $member->update($this->normalize($data));

        return $member->refresh();
    }

    /**
     * Hapus anggota yang belum punya saldo. Transaksi lamanya tetap ada (member_id dilepas).
     * Anggota bersaldo sebaiknya dinonaktifkan saja.
     *
     * @throws ValidationException
     */
    public function delete(Member $member): void
    {
        if ((float) $member->balance > 0) {
            throw ValidationException::withMessages([
                'member' => 'Anggota masih punya saldo tabungan. Tarik saldonya dulu atau nonaktifkan anggota.',
            ]);
        }

        $member->delete();
    }

    /**
     * @param  array{search?: string, type?: string, class?: string, include_inactive?: bool}  $filters
     */
    public function query(array $filters = []): Builder
    {
        return Member::query()
            ->search($filters['search'] ?? '')
            ->ofType(($filters['type'] ?? '') ?: null)
            ->when(($filters['class'] ?? '') !== '', fn ($q) => $q->where('class', mb_strtoupper($filters['class'])))
            ->unless($filters['include_inactive'] ?? false, fn ($q) => $q->active())
            // Guru dulu, lalu siswa per kelas, lalu nama.
            ->orderByRaw("CASE WHEN type = 'guru' THEN 0 ELSE 1 END")
            ->orderBy('class')
            ->orderByRaw('LOWER(name)')
            ->orderBy('id');
    }

    public function paginate(array $filters, int $perPage = 25, ?int $page = null): LengthAwarePaginator
    {
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];
        $paginator = $this->query($filters)->paginate($perPage, ['*'], 'page', $page);

        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->total() > 0) {
            $paginator = $this->query($filters)->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return $paginator->through(fn (Member $m) => self::toArray($m));
    }

    /** Pencarian cepat anggota aktif (mis. memilih anggota di layar kasir). */
    public function search(string $term, int $limit = 10): array
    {
        if (trim($term) === '') {
            return [];
        }

        return Member::active()->search($term)
            ->orderByRaw('LOWER(name)')
            ->limit($limit)
            ->get()
            ->map(fn (Member $m) => self::toArray($m))
            ->all();
    }

    /** Ringkasan anggota aktif: jumlah per jenis & total saldo tabungan. */
    public function stats(): array
    {
        $row = Member::active()->selectRaw("
            COUNT(*) AS total,
            SUM(CASE WHEN type = 'siswa' THEN 1 ELSE 0 END) AS siswa,
            SUM(CASE WHEN type = 'guru' THEN 1 ELSE 0 END) AS guru,
            COALESCE(SUM(balance), 0) AS balance
        ")->first();

        return [
            'total' => (int) $row->total,
            'siswa' => (int) $row->siswa,
            'guru' => (int) $row->guru,
            'balance' => (int) round((float) $row->balance),
        ];
    }

    /** @return list<string> */
    public function classes(): array
    {
        return Member::whereNotNull('class')->distinct()->orderBy('class')->pluck('class')->all();
    }

    /** Bentuk array anggota (sama dengan data tiruan halaman Anggota). */
    public static function toArray(Member $m): array
    {
        return [
            'id' => $m->id,
            'member_code' => $m->member_code,
            'name' => $m->name,
            'type' => $m->type,
            'type_label' => Member::TYPES[$m->type] ?? $m->type,
            'class' => $m->class,
            'phone' => $m->phone,
            'balance' => (int) round((float) $m->balance),
            'is_active' => $m->is_active,
            'joined_at' => $m->joined_at?->toDateString(),
            'display_name' => $m->display_name,
        ];
    }

    private function normalize(array $data): array
    {
        $out = collect($data)->only(['member_code', 'name', 'type', 'class', 'phone', 'is_active'])->all();
        $out['name'] = trim($out['name']);
        $out['class'] = ($out['type'] ?? 'siswa') === 'guru' ? null : mb_strtoupper(trim((string) ($out['class'] ?? '')));
        $out['phone'] = filled($out['phone'] ?? null) ? trim($out['phone']) : null;
        if (blank($out['member_code'] ?? null)) {
            unset($out['member_code']);
        }

        return $out;
    }
}
