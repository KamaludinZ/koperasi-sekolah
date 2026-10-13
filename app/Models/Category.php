<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SoftDeletesWithReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Kategori barang: nama unik (di antara yang aktif), warna penanda, urutan tampil, keterangan.
 * Dihapus lunak; kategori yang masih dipakai barang tidak bisa dihapus (model & kunci tamu "restrict").
 * Perubahan nama/warna/urutan tercatat di audit.
 */
class Category extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletesWithReason;

    public const AUDIT = ['type' => 'category', 'category' => 'barang', 'noun' => 'kategori', 'fields' => ['name', 'color', 'description']];

    /** Warna penanda yang tersedia (kelas Tailwind bg-{warna}-500). */
    public const COLORS = ['emerald', 'amber', 'sky', 'violet', 'teal', 'rose', 'stone'];

    protected $fillable = ['name', 'color', 'position', 'description'];

    protected $attributes = ['color' => 'emerald', 'position' => 0];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    protected static function booted(): void
    {
        // Kategori baru ditaruh di urutan paling akhir.
        static::creating(function (Category $c) {
            if (! $c->position) {
                $c->position = (int) static::withTrashed()->max('position') + 1;
            }
        });

        // Kategori yang masih dipakai barang aktif tidak boleh dihapus; pindahkan barangnya dulu lewat
        // CategoryService::delete(). Hapus permanen juga ditolak selama barang terhapus lunak masih ada.
        static::deleting(function (Category $c) {
            $used = $c->isForceDeleting()
                ? Product::withTrashed()->where('category_id', $c->id)->count()
                : Product::where('category_id', $c->id)->count();
            if ($used > 0) {
                throw ValidationException::withMessages([
                    'pindah_ke' => "Kategori {$c->name} masih dipakai {$used} barang. Pindahkan barangnya ke kategori lain dulu.",
                ]);
            }
        });
    }

    /** Urutan tampil: posisi lalu nama. */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
