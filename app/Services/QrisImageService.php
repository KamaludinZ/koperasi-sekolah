<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gambar QRIS statis koperasi (dari bank/penyedia QRIS) sebagai berkas di disk privat, disimpan di
 * pengaturan profile.qris. Ditampilkan di modal bayar kasir saat metode QRIS dipilih agar pembeli
 * bisa memindai. Validasi isi berkas sama dengan logo (UploadPolicy jenis `logo`) tanpa syarat persegi.
 */
class QrisImageService
{
    public const DISK = LogoService::DISK;

    public const DIR = LogoService::DIR;

    public const FILE_PATTERN = 'qris-[A-Za-z0-9]{16}\.(png|jpg|webp)';

    public function __construct(private SettingsService $settings) {}

    public static function rules(): array
    {
        $rules = array_values(array_filter(UploadPolicy::rules('logo', required: false), fn ($r) => $r !== UploadPolicy::LOGO_DIMENSIONS));
        $rules[] = Rule::dimensions()->minWidth(200)->minHeight(200);

        return $rules;
    }

    public static function messages(string $field = 'qrisUpload'): array
    {
        return UploadPolicy::messages('logo', $field) + [$field.'.dimensions' => 'Gambar QRIS minimal 200×200 piksel agar mudah dipindai.'];
    }

    public function store(UploadedFile $file, ?User $by = null): array
    {
        $ext = LogoService::EXTENSIONS[$file->getMimeType()] ?? 'png';
        $name = 'qris-'.Str::random(16).".{$ext}";
        Storage::disk(self::DISK)->putFileAs(self::DIR, $file, $name);

        $old = $this->settings->section('profile')['qris'] ?? null;
        $all = $this->settings->save('profile', ['qris' => $name], $by);
        $this->deleteFile($old);

        return $all;
    }

    public function delete(?User $by = null): array
    {
        $old = $this->settings->section('profile')['qris'] ?? null;
        $all = $this->settings->save('profile', ['qris' => null], $by);
        $this->deleteFile($old);

        return $all;
    }

    public static function url(?string $value): ?string
    {
        return $value && preg_match('/^'.self::FILE_PATTERN.'$/', $value) ? route('qris.image', $value) : null;
    }

    public static function path(string $name): string
    {
        return self::DIR.'/'.$name;
    }

    private function deleteFile(?string $name): void
    {
        if ($name && preg_match('/^'.self::FILE_PATTERN.'$/', $name)) {
            Storage::disk(self::DISK)->delete(self::path($name));
        }
    }
}
