<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu baris jejak audit. Tambahkan lewat App\Services\AuditLog; tidak pernah diubah/dihapus.
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const CATEGORIES = [
        'auth' => 'Login & akses',
        'transaksi' => 'Penjualan',
        'tabungan' => 'Tabungan',
        'kas' => 'Kas & shift',
        'stok' => 'Stok',
        'barang' => 'Barang',
        'anggota' => 'Anggota',
        'pengguna' => 'Pengguna',
        'laporan' => 'Laporan',
        'pengaturan' => 'Pengaturan',
        'pengumuman' => 'Pengumuman',
    ];

    /** Kategori yang termasuk sirkulasi uang/barang. */
    public const CIRCULATION = ['transaksi', 'tabungan', 'kas', 'stok'];

    protected $fillable = [
        'event', 'category', 'description', 'subject_type', 'subject_id', 'subject_label',
        'actor_id', 'actor_name', 'actor_role', 'amount', 'properties', 'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function scopeBetweenDates(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        $query->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end));
    }

    /** Model ini hanya-tambah. */
    public function save(array $options = []): bool
    {
        return $this->exists ? false : parent::save($options);
    }

    public function delete(): ?bool
    {
        return false;
    }
}
