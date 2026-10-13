<?php

namespace App\Models\Concerns;

use App\Observers\MasterDataObserver;

/**
 * Data master yang setiap perubahannya dicatat otomatis ke jejak audit oleh MasterDataObserver
 * (dibuat/diubah/dihapus/dipulihkan beserta nilai sebelum & sesudah kolom yang dipantau).
 *
 * Model wajib mendefinisikan konstanta AUDIT:
 * ['type' => subject_type, 'category' => kategori audit, 'noun' => sebutan, 'fields' => kolom dipantau].
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(MasterDataObserver::class);
    }

    /** @return array{type: string, category: string, noun: string, fields: list<string>} */
    public static function auditConfig(): array
    {
        return static::AUDIT;
    }

    /** Label objek di daftar audit, mis. "ATK001 · Buku Tulis". */
    public function auditLabel(): string
    {
        return (string) $this->getAttribute('name');
    }

    /** Sebutan objek di kalimat audit, mis. "Buku Tulis". */
    public function auditTitle(): string
    {
        return (string) $this->getAttribute('name');
    }

    /**
     * Ubah nilai kolom menjadi bentuk yang terbaca (mis. id kategori → nama kategori).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function auditValues(array $values): array
    {
        return $values;
    }
}
