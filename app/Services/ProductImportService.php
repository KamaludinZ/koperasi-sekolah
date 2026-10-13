<?php

namespace App\Services;

use App\Models\ProductImport;
use App\Models\User;
use App\Support\ProductImportFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Impor barang dari berkas CSV/XLSX. Tahap unggah: berkas diperiksa UploadPolicy (jenis, ukuran,
 * isi), disimpan di disk privat `local` (imports/), dan dicatat sebagai impor berstatus "diunggah"
 * beserta cara menyimpan & opsi perbarui stok. Petugas dibatasi beberapa impor terbuka sekaligus.
 */
class ProductImportService
{
    public const DISK = 'local';

    public const DIR = 'imports';

    /** Batas impor yang belum selesai (diunggah/pratinjau) per petugas. */
    public const MAX_OPEN = 5;

    public static function rules(): array
    {
        return [
            'berkas' => UploadPolicy::rules('import'),
            'mode' => ['required', Rule::in(array_keys(ProductImportFormat::MODES))],
            'perbarui_stok' => ['nullable', 'boolean'],
        ];
    }

    public static function messages(): array
    {
        return UploadPolicy::messages('import', 'berkas') + [
            'berkas.required' => 'Pilih berkas CSV atau Excel terlebih dahulu.',
            'mode.in' => 'Cara menyimpan tidak dikenal.',
        ];
    }

    /**
     * @throws ValidationException bila petugas sudah punya terlalu banyak impor terbuka
     */
    public function upload(UploadedFile $file, string $mode, bool $updateStock, ?User $by = null): ProductImport
    {
        $by ??= auth()->user();
        $open = ProductImport::where('user_id', $by?->id)->whereIn('status', ['diunggah', 'pratinjau'])->count();
        if ($open >= self::MAX_OPEN) {
            throw ValidationException::withMessages(['berkas' => 'Masih ada '.$open.' impor yang belum diselesaikan. Simpan atau batalkan dulu sebelum mengunggah lagi.']);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs(self::DIR.'/'.now()->format('Y/m'), Str::random(24).'.'.$ext, self::DISK);
        $name = Str::limit(preg_replace('/[^\pL\pN ._()-]/u', '_', $file->getClientOriginalName()), 190, '');

        $import = ProductImport::create([
            'user_id' => $by?->id,
            'file_name' => $name,
            'format' => $ext,
            'file_path' => $path,
            'file_size' => (int) $file->getSize(),
            'status' => 'diunggah',
            'mode' => $mode,
            'update_stock' => $mode !== 'tambah' && $updateStock,
        ]);

        AuditLog::record('import.uploaded', 'barang', "Berkas impor barang {$name} diunggah", ['type' => 'product_import', 'id' => $import->id, 'label' => $name],
            ['mode' => $mode, 'update_stock' => $import->update_stock, 'size' => $import->file_size]);

        return $import;
    }

    /** Hapus berkas asli impor dari disk (dipakai saat impor dibatalkan/dibersihkan). */
    public function deleteFile(ProductImport $import): void
    {
        if ($import->file_path) {
            Storage::disk(self::DISK)->delete($import->file_path);
            $import->forceFill(['file_path' => null])->save();
        }
    }

    /** @return array<string, mixed> */
    public static function toArray(ProductImport $i): array
    {
        return [
            'id' => $i->id,
            'file_name' => $i->file_name,
            'format' => $i->format,
            'status' => $i->status,
            'mode' => $i->mode,
            'update_stock' => $i->update_stock,
            'total_rows' => $i->total_rows,
            'created' => $i->created,
            'updated' => $i->updated,
            'skipped' => $i->skipped,
            'failed' => $i->failed,
            'file_error' => $i->file_error,
            'user' => $i->user?->name ?? 'Sistem',
            'created_at' => $i->created_at?->toDateTimeString(),
            'finished_at' => $i->finished_at?->toDateTimeString(),
        ];
    }
}
