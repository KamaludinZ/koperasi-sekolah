<?php

namespace App\Models;

use App\Services\AuditIntegrity;
use App\Services\LoanAuditChain;
use App\Services\LoanAutoAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu baris jejak audit. Tambahkan lewat App\Services\AuditLog; tidak pernah diubah/dihapus.
 * Perubahan data master menyimpan `action`, `old_values`, `new_values`, dan `reason`.
 * Setiap baris ditandai `checksum` (AuditIntegrity) saat dibuat; database menolak ubah/hapus (AuditGuard).
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const CATEGORIES = [
        'auth' => 'Login & akses',
        'transaksi' => 'Penjualan',
        'tabungan' => 'Tabungan',
        'simpanan' => 'Simpanan',
        'pinjaman' => 'Pinjaman',
        'kas' => 'Kas & shift',
        'stok' => 'Stok',
        'barang' => 'Barang',
        'anggota' => 'Anggota',
        'pengguna' => 'Pengguna',
        'laporan' => 'Laporan',
        'pengaturan' => 'Pengaturan',
        'pengumuman' => 'Pengumuman',
        'pengadaan' => 'Pengadaan',
        'pesanan' => 'Pesanan anggota',
    ];

    /** Aksi pada data master (kolom `action`); null untuk aktivitas lain. */
    public const ACTIONS = [
        'dibuat' => 'Dibuat',
        'diubah' => 'Diubah',
        'dihapus' => 'Dihapus',
        'dipulihkan' => 'Dipulihkan',
    ];

    /** Kategori yang termasuk sirkulasi uang/barang. */
    public const CIRCULATION = ['transaksi', 'tabungan', 'simpanan', 'pinjaman', 'kas', 'stok'];

    protected $fillable = [
        'event', 'category', 'description', 'subject_type', 'subject_id', 'subject_label',
        'actor_id', 'actor_name', 'actor_role', 'amount', 'properties', 'ip_address', 'user_agent', 'created_at',
        'action', 'old_values', 'new_values', 'reason', 'impersonation_id', 'on_behalf_type', 'on_behalf_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log) {
            $log->created_at ??= now();
            $log->checksum = AuditIntegrity::checksum($log->getAttributes());
        });
        // jejak pinjaman ikut dirantai HMAC (LoanAuditChain)
        static::created(function (ActivityLog $log) {
            if ($log->category === 'pinjaman') {
                if ($log->subject_type === 'loan' && ! in_array($log->event, LoanAutoAudit::EVENTS, true)) {
                    LoanAutoAudit::markExplicit((int) $log->subject_id);
                }
                app(LoanAuditChain::class)->append('activity', $log->id, $log->subject_type === 'loan' ? (int) $log->subject_id : null, $log->checksum);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * Perubahan per kolom {kolom: {from, to}} dari old_values/new_values.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function getFieldChangesAttribute(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        return collect(array_keys($old + $new))
            ->mapWithKeys(fn ($k) => [$k => ['from' => $old[$k] ?? null, 'to' => $new[$k] ?? null]])
            ->all();
    }

    /** Hanya baris perubahan data master (punya aksi). */
    public function scopeMasterChanges(Builder $query): void
    {
        $query->whereNotNull('action');
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
