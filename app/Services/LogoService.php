<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logo koperasi sebagai berkas di disk privat (storage/app/private/koperasi).
 * Pengaturan profile.logo menyimpan nama berkasnya saja (mis. logo-xxxx.png); nama
 * acak berubah tiap unggah sehingga bisa di-cache lama oleh peramban. Berkas lama
 * dihapus saat diganti/dihapus. Ditayangkan lewat route publik `logo` (LogoController).
 */
class LogoService
{
    public const DISK = 'local';

    public const DIR = 'koperasi';

    /** Hanya gambar raster: SVG ditolak karena bisa memuat skrip. */
    public const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public const FILE_PATTERN = 'logo-[A-Za-z0-9]{16}\.(png|jpg|webp)';

    public function __construct(private SettingsService $settings) {}

    public static function rules(): array
    {
        return ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'mimetypes:'.implode(',', array_keys(self::EXTENSIONS)), 'max:512', 'dimensions:min_width=64,min_height=64,max_width=2000,max_height=2000'];
    }

    /** Pesan validasi untuk atribut $field. */
    public static function messages(string $field = 'logo'): array
    {
        return [
            "{$field}.required" => 'Pilih berkas logo.',
            "{$field}.image" => 'Logo harus berupa gambar.',
            "{$field}.mimes" => 'Gunakan PNG, JPG, atau WEBP.',
            "{$field}.mimetypes" => 'Gunakan PNG, JPG, atau WEBP.',
            "{$field}.max" => 'Ukuran logo maksimal 512 KB.',
            "{$field}.dimensions" => 'Logo minimal 64×64 dan maksimal 2000×2000 piksel.',
        ];
    }

    /** Simpan logo baru (sudah divalidasi), hapus berkas lama. Mengembalikan pengaturan terbaru. */
    public function store(UploadedFile $file, ?User $by = null): array
    {
        $ext = self::EXTENSIONS[$file->getMimeType()] ?? 'png';
        $name = 'logo-'.Str::random(16).".{$ext}";
        Storage::disk(self::DISK)->putFileAs(self::DIR, $file, $name);

        $old = $this->settings->section('profile')['logo'];
        $all = $this->settings->save('profile', ['logo' => $name], $by);
        $this->deleteFile($old);

        return $all;
    }

    /** Hapus logo (kembali ke lambang bawaan). */
    public function delete(?User $by = null): array
    {
        $old = $this->settings->section('profile')['logo'];
        $all = $this->settings->save('profile', ['logo' => null], $by);
        $this->deleteFile($old);

        return $all;
    }

    /** Nilai profile.logo → URL untuk <img>. Data URI lama (tahap awal) dibiarkan apa adanya. */
    public static function url(?string $value): ?string
    {
        return match (true) {
            blank($value) => null,
            str_starts_with($value, 'data:image/') => $value,
            (bool) preg_match('/^'.self::FILE_PATTERN.'$/', $value) => route('logo', $value),
            default => null,
        };
    }

    /**
     * Logo sebagai data URI untuk PDF (dompdf tidak memuat URL). Null bila tidak ada
     * berkas logo. Data URI lama (tahap awal) dikembalikan apa adanya.
     */
    public static function dataUri(?string $value): ?string
    {
        if (blank($value) || str_starts_with($value, 'data:image/')) {
            return $value ?: null;
        }
        if (! preg_match('/^'.self::FILE_PATTERN.'$/', $value) || ! Storage::disk(self::DISK)->exists(self::path($value))) {
            return null;
        }
        $disk = Storage::disk(self::DISK);

        return 'data:'.$disk->mimeType(self::path($value)).';base64,'.base64_encode($disk->get(self::path($value)));
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
