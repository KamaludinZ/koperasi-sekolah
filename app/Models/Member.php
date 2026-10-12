<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anggota koperasi (siswa/guru). `balance` = saldo tabungan saat ini;
 * hanya boleh diubah lewat layanan tabungan agar selalu cocok dengan mutasinya.
 */
class Member extends Model
{
    use HasFactory;

    public const TYPES = ['siswa' => 'Siswa', 'guru' => 'Guru'];

    protected $fillable = [
        'member_code', 'name', 'type', 'class', 'phone', 'is_active', 'joined_at',
    ];

    protected $attributes = [
        'type' => 'siswa',
        'balance' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
            'joined_at' => 'date',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Mutasi tabungan (setor/tarik). */
    public function savings(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, ?string $type): void
    {
        $query->when($type, fn ($q) => $q->where('type', $type));
    }

    /** Cari nama, kode anggota, atau kelas (tanpa membedakan huruf besar/kecil). */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = mb_strtolower(trim((string) $term));
        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $query->where(fn (Builder $w) => $w
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
            ->orWhereRaw("LOWER(member_code) LIKE ? ESCAPE '\\'", [$like])
            ->orWhereRaw("LOWER(class) LIKE ? ESCAPE '\\'", [$like]));
    }

    /** Label tampilan, mis. "Andi Pratama (7A)" atau "Pak Budi (Guru)". */
    public function getDisplayNameAttribute(): string
    {
        return $this->name.' ('.($this->type === 'guru' ? 'Guru' : $this->class).')';
    }

    /** Kode harus unik tanpa membedakan huruf: selalu simpan huruf besar. */
    protected function setMemberCodeAttribute(string $value): void
    {
        $this->attributes['member_code'] = mb_strtoupper(trim($value));
    }
}
