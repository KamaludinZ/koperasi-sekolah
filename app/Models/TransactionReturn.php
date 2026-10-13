<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pengajuan retur barang (RTR-) atau pembatalan transaksi (BTL-) beserta keputusannya. */
class TransactionReturn extends Model
{
    use HasFactory;

    /** Awalan nomor per jenis: RTR-YYYYMMDD-NNNN / BTL-YYYYMMDD-NNNN. */
    public const PREFIXES = ['retur' => 'RTR', 'batal' => 'BTL'];

    public const KINDS = ['retur' => 'Retur barang', 'batal' => 'Pembatalan'];

    public const STATUSES = ['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    public const REFUND_METHODS = ['tunai' => 'Uang tunai', 'qris' => 'Transfer balik', 'tabungan' => 'Masuk saldo tabungan', 'bon' => 'Mengurangi bon'];

    public const RETURN_REASONS = ['rusak' => 'Barang rusak/cacat', 'salah_ambil' => 'Salah ambil barang', 'kedaluwarsa' => 'Kedaluwarsa', 'lainnya' => 'Lainnya'];

    public const VOID_REASONS = ['salah_input' => 'Salah input kasir', 'batal_beli' => 'Pembeli batal membeli', 'ganda' => 'Transaksi tercatat ganda', 'lainnya' => 'Lainnya'];

    protected $fillable = [
        'number', 'kind', 'transaction_id', 'status', 'reason_key', 'reason', 'note', 'restock', 'refund_method', 'amount', 'quantity',
        'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $attributes = ['status' => 'menunggu', 'restock' => true];

    protected function casts(): array
    {
        return ['restock' => 'boolean', 'amount' => 'integer', 'quantity' => 'integer', 'requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionReturnItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'menunggu');
    }

    /** Pengajuan yang masih berlaku (menunggu atau disetujui). */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ['menunggu', 'disetujui']);
    }

    public function scopeOfKind(Builder $query, ?string $kind): void
    {
        $query->when($kind, fn ($q) => $q->where('kind', $kind));
    }

    /** Sudah diputus (disetujui/ditolak). */
    public function scopeDecided(Builder $query): void
    {
        $query->whereIn('status', ['disetujui', 'ditolak']);
    }

    public function isPending(): bool
    {
        return $this->status === 'menunggu';
    }

    public function isVoid(): bool
    {
        return $this->kind === 'batal';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function refundLabel(): string
    {
        return self::REFUND_METHODS[$this->refund_method] ?? $this->refund_method;
    }
}
