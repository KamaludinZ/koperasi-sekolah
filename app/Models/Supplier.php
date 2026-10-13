<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SoftDeletesWithReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Pemasok barang koperasi. `terms_days` 0 = bayar tunai, N = tempo N hari setelah barang diterima.
 * Kategori yang dipasok dipakai untuk menyarankan supplier saat stok barang menipis.
 * Dihapus lunak; setiap perubahan tercatat di audit.
 */
class Supplier extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletesWithReason;

    public const AUDIT = [
        'type' => 'supplier', 'category' => 'pengadaan', 'noun' => 'supplier',
        'fields' => ['name', 'contact', 'phone', 'email', 'address', 'terms_days', 'note', 'is_active'],
    ];

    /** Batas tempo pembayaran (hari). */
    public const MAX_TERMS_DAYS = 120;

    protected $fillable = ['name', 'contact', 'phone', 'email', 'address', 'terms_days', 'note', 'is_active'];

    protected $attributes = ['terms_days' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['terms_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTrashed()->orderBy('position');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isCredit(): bool
    {
        return $this->terms_days > 0;
    }

    /** Teks syarat bayar: "Tunai" atau "Tempo N hari". */
    public function termsLabel(): string
    {
        return $this->terms_days > 0 ? "Tempo {$this->terms_days} hari" : 'Tunai';
    }
}
