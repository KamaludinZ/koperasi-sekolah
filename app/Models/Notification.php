<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Notifikasi & pengumuman koperasi (tabel notifications). Status dibaca per petugas
 * ada di tabel notification_reads (relasi readers).
 */
class Notification extends Model
{
    use HasFactory;

    public const TYPES = ['stok' => 'Stok', 'pengumuman' => 'Pengumuman', 'sistem' => 'Sistem'];

    public const TARGETS = ['semua' => 'Semua petugas', 'admin' => 'Admin', 'kasir' => 'Kasir', 'pengurus' => 'Pengurus', 'anggota' => 'Anggota (disampaikan petugas)'];

    protected $fillable = ['type', 'title', 'message', 'target', 'url', 'product_id', 'dedupe_key', 'show_public', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'show_public' => 'boolean',
            'expires_at' => 'date',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_reads')->withPivot(['read_at', 'dismissed_at']);
    }

    /** Masih berlaku (tanpa tanggal berakhir, atau belum lewat). */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()));
    }

    /** Untuk petugas berperan $role: Admin melihat semua; lainnya target semua, anggota, atau perannya. */
    public function scopeForRole(Builder $query, ?string $role): void
    {
        if ($role !== 'admin') {
            $query->whereIn('target', array_filter(['semua', 'anggota', $role]));
        }
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('type', 'pengumuman')->where('show_public', true)->active();
    }
}
