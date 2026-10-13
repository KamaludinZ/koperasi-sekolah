<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mutasi tabungan anggota (setor/tarik). Tambahkan lewat layanan tabungan
 * agar saldo anggota & catatan mutasi selalu konsisten. Tidak diubah setelah dibuat.
 */
class SavingsTransaction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_DEPOSIT = 'setor';

    public const TYPE_WITHDRAWAL = 'tarik';

    public const TYPES = [
        self::TYPE_DEPOSIT => 'Setoran',
        self::TYPE_WITHDRAWAL => 'Penarikan',
    ];

    protected $fillable = ['member_id', 'type', 'amount', 'balance_before', 'balance_after', 'note', 'source_type', 'source_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed(); // riwayat tetap menampilkan data yang dihapus lunak
    }

    /** Petugas yang mencatat. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Nominal bertanda: + setor, − tarik. */
    public function getSignedAmountAttribute(): int
    {
        $amount = (int) round((float) $this->amount);

        return $this->type === self::TYPE_WITHDRAWAL ? -$amount : $amount;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function scopeOfType(Builder $query, ?string $type): void
    {
        $query->when($type, fn ($q) => $q->where('type', $type));
    }

    public function scopeBetweenDates(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        $query->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end));
    }
}
