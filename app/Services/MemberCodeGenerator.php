<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Kode anggota unik berformat <S|G>-<tahun>-<urut>, mis. S-2026-001 (siswa) atau
 * G-2026-004 (guru). Nomor urut melanjutkan nomor terbesar tahun itu (celah tidak diisi
 * ulang) dan tumbuh melewati 3 digit bila perlu.
 */
class MemberCodeGenerator
{
    public const PREFIXES = ['siswa' => 'S', 'guru' => 'G'];

    public function next(string $type, ?int $year = null): string
    {
        $prefix = $this->prefix($type, $year);

        $last = Member::where('member_code', 'like', $prefix.'%')
            ->pluck('member_code')
            ->map(fn ($code) => substr($code, strlen($prefix)))
            ->filter(fn ($n) => ctype_digit($n))
            ->map(fn ($n) => (int) $n)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan anggota baru. Bila `member_code` kosong, kode dibuat otomatis dan
     * dicoba ulang jika bentrok dengan penyimpanan bersamaan.
     */
    public function create(array $attributes): Member
    {
        if (filled($attributes['member_code'] ?? null)) {
            return Member::create($attributes);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(fn () => Member::create([
                    ...$attributes,
                    'member_code' => $this->next($attributes['type'] ?? 'siswa'),
                ]));
            } catch (UniqueConstraintViolationException) {
                // kode baru saja dipakai penyimpanan lain — ambil nomor berikutnya
            }
        }

        throw new \RuntimeException('Gagal membuat kode anggota unik.');
    }

    private function prefix(string $type, ?int $year): string
    {
        return (self::PREFIXES[$type] ?? self::PREFIXES['siswa']).'-'.($year ?? now()->year).'-';
    }
}
