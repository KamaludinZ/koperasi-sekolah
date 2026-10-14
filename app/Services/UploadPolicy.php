<?php

namespace App\Services;

use App\Rules\SafeUpload;

/**
 * Aturan unggah berkas terpusat. Ukuran maksimal & jenis yang diterima diambil dari pengaturan
 * Keamanan & retensi (`security.{logo|import}_max_kb`, `security.{logo|import}_types`), lalu
 * diperiksa berlapis: akhiran (mimes), jenis isi menurut server (mimetypes), dan isi berkas
 * (SafeUpload: gambar asli, CSV teks, XLSX tanpa makro). SVG & berkas bermakro selalu ditolak.
 */
class UploadPolicy
{
    public const KINDS = ['logo' => 'Logo', 'import' => 'Impor barang'];

    /** Jenis isi (MIME) yang diterima per akhiran. */
    public const MIMES = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pdf' => ['application/pdf'],
    ];

    /** Bukti serah terima (pencairan pinjaman): foto/scan gambar atau PDF, maks. 4 MB. */
    public const PROOF_TYPES = ['jpg', 'png', 'webp', 'pdf'];

    public const PROOF_MAX_KB = 4096;

    public static function proofRules(bool $required = false): array
    {
        $mimes = collect(self::PROOF_TYPES)->flatMap(fn ($t) => self::MIMES[$t])->unique()->values()->all();

        return [$required ? 'required' : 'nullable', 'file', 'max:'.self::PROOF_MAX_KB, 'mimes:jpg,jpeg,png,webp,pdf',
            'mimetypes:'.implode(',', $mimes), new SafeUpload('proof')];
    }

    public static function proofMessages(string $field): array
    {
        return [
            "{$field}.required" => 'Pilih berkas bukti.',
            "{$field}.file" => 'Unggahan gagal. Coba pilih berkasnya lagi.',
            "{$field}.uploaded" => 'Unggahan gagal. Coba pilih berkasnya lagi.',
            "{$field}.max" => 'Ukuran bukti maksimal 4 MB.',
            "{$field}.mimes" => 'Gunakan foto JPG, PNG, WEBP, atau PDF.',
            "{$field}.mimetypes" => 'Gunakan foto JPG, PNG, WEBP, atau PDF.',
        ];
    }

    /** Batas gambar logo (piksel). */
    public const LOGO_DIMENSIONS = 'dimensions:min_width=64,min_height=64,max_width=2000,max_height=2000';

    /** @return array{max_kb: int, types: list<string>} */
    public static function limits(string $kind): array
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        $s = app(SettingsService::class)->security();

        return ['max_kb' => (int) $s["{$kind}_max_kb"], 'types' => array_values($s["{$kind}_types"])];
    }

    /** Akhiran yang diterima (jpg ikut menerima jpeg). */
    public static function extensions(string $kind): array
    {
        return collect(self::limits($kind)['types'])->flatMap(fn ($t) => $t === 'jpg' ? ['jpg', 'jpeg'] : [$t])->all();
    }

    public static function rules(string $kind, bool $required = true): array
    {
        $limits = self::limits($kind);
        $mimes = collect($limits['types'])->flatMap(fn ($t) => self::MIMES[$t] ?? [])->unique()->values()->all();

        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'file',
            $kind === 'logo' ? 'image' : null,
            'max:'.$limits['max_kb'],
            'mimes:'.implode(',', self::extensions($kind)),
            'mimetypes:'.implode(',', $mimes),
            $kind === 'logo' ? self::LOGO_DIMENSIONS : null,
            new SafeUpload($kind),
        ]));
    }

    /** Pesan validasi untuk atribut $field. */
    public static function messages(string $kind, string $field): array
    {
        $limits = self::limits($kind);
        $types = collect($limits['types'])->map(fn ($t) => strtoupper($t))->join(', ', ' atau ');
        $size = $limits['max_kb'] >= 1024 ? rtrim(rtrim(number_format($limits['max_kb'] / 1024, 1, ',', ''), '0'), ',').' MB' : $limits['max_kb'].' KB';

        return [
            "{$field}.required" => $kind === 'logo' ? 'Pilih berkas logo.' : 'Pilih berkas yang akan diunggah.',
            "{$field}.file" => 'Unggahan gagal. Coba pilih berkasnya lagi.',
            "{$field}.uploaded" => 'Unggahan gagal. Coba pilih berkasnya lagi.',
            "{$field}.image" => 'Logo harus berupa gambar.',
            "{$field}.mimes" => "Gunakan berkas {$types}.",
            "{$field}.mimetypes" => "Gunakan berkas {$types}.",
            "{$field}.max" => ($kind === 'logo' ? 'Ukuran logo' : 'Ukuran berkas')." maksimal {$size}.",
            "{$field}.dimensions" => 'Logo minimal 64×64 dan maksimal 2000×2000 piksel.',
        ];
    }
}
