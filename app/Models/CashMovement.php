<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setoran (in) atau penarikan (out) uang laci di luar penjualan pada sebuah shift,
 * dengan alasan wajib. Tidak diubah setelah dibuat.
 */
class CashMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    public const TYPES = [self::TYPE_IN => 'Uang masuk laci', self::TYPE_OUT => 'Uang keluar laci'];

    protected $fillable = ['cash_shift_id', 'type', 'amount', 'reason', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    /** Petugas yang mencatat. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Nominal bertanda: + masuk, − keluar. */
    public function getSignedAmountAttribute(): int
    {
        $amount = (int) round((float) $this->amount);

        return $this->type === self::TYPE_OUT ? -$amount : $amount;
    }
}
